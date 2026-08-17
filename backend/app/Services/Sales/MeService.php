<?php

namespace App\Services\Sales;

use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Enums\TargetMetric;
use App\Models\AgentTarget;
use App\Models\Notification;
use App\Models\User;
use App\Services\Gamification\LevelService;
use App\Services\Referral\ClosedDealPredicate;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-053 / ADR-016 Phase 2 — the read-side aggregation powering the
 * Agent Portal personal home hub. Two shapes:
 *   home()  — profile, personal gamification (level/xp/badges), goal rings
 *             (XP→Level + admin-set sales/deal/client targets vs actual),
 *             pending-task COUNTS, unread notification count.
 *   tasks() — the detailed pending-work lists (due follow-ups, open deals,
 *             not-yet-passed exams).
 * Everything is the AGENT'S OWN data — never other people's (the whole
 * point of the personal app). Read-only; money stays integer satang (BR-3).
 *
 * TASK-180 §2 (A1/A2, 2026-08-13) — two numbers on this screen used to
 * answer questions of their own. Read this before changing either:
 *
 *   open deals            NOT closed, per the ONE shared predicate
 *                         (ClosedDealPredicate::applyOpen). Both tasks()
 *                         and the home badge used to carry their own
 *                         `whereNotIn(current_stage, [complete_payment,
 *                         ongoing_next_meeting])`, so once ADR-026 let a
 *                         template continue into จัดส่ง / นัดใช้บริการ /
 *                         ติดตามผล, a deal the customer had already paid
 *                         for came back to the agent as work still to do.
 *   goals[].actual_value  month-to-date, from that agent's PAID ORDERS
 *                         (D1/D2), bucketed on the SALE date (D3) — see
 *                         monthActuals(). All three metrics moved off the
 *                         commission ledger together: money the company
 *                         has disbursed is a different axis from money the
 *                         customer paid, and reading the target bar off
 *                         the payroll axis meant an agent whose customer
 *                         had paid in full watched 0% until payday.
 *
 * Every query here is filtered by the agent's OWN company_id as well as
 * their own id (BR-6 / §5): these are raw DB::table() builders, which
 * TenantScope does not touch.
 */
class MeService
{
    public function __construct(
        private LevelService $levelService,
        private DownlineService $downlineService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function home(User $user): array
    {
        $level = $this->levelService->currentLevelForUser($user);
        $badges = (int) DB::table('user_badges')->where('user_id', $user->id)->count();

        $period = Carbon::now()->format('Y-m');
        $monthStart = Carbon::now()->startOfMonth();
        $actuals = $this->monthActuals($user, $monthStart);

        $goals = AgentTarget::query()
            ->where('agent_id', $user->id)
            ->where('period', $period)
            ->get()
            ->map(fn (AgentTarget $t) => [
                'metric' => $t->metric->value,
                'metric_label' => $t->metric->label(),
                'target_value' => (int) $t->target_value,
                'actual_value' => $actuals[$t->metric->value] ?? 0,
                'progress' => $t->target_value > 0
                    ? min(100, round(($actuals[$t->metric->value] ?? 0) / $t->target_value * 100, 1))
                    : 0.0,
            ])
            ->all();

        return [
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar_url' => $user->avatar_path ? Storage::disk('public')->url($user->avatar_path) : null,
            ],
            'gamification' => [
                'level_number' => $level['level_number'],
                'total_xp' => $level['total_xp'],
                'level_xp_floor' => $level['xp_required'],
                'next_level_xp' => $level['next_level_xp_required'],
                'badges_count' => $badges,
            ],
            'goals' => $goals,
            // TASK-107 / ADR-024 §9 — how many people report DIRECTLY to
            // this user. Home renders the "ทีมของฉัน" menu entry only when
            // this is > 0, so it must arrive with the rest of the home
            // payload rather than costing a second request on every app
            // open. Answered server-side from users.manager_id via the same
            // DownlineService every /me/team endpoint uses (ADR-024 §1:
            // leadership is emergent, never a client-supplied flag) — a
            // non-leader gets 0, and a Super Admin (no company) gets 0 too.
            // TASK-111 (D1) — 0 when the company has switched the team monitor
            // OFF, so HomeView stops rendering the "ทีมของฉัน" entry with no
            // frontend change (it already keys off this count alone). Without
            // this the menu still appeared under a switch the Admin UI
            // describes as "ปิดอยู่ = หัวหน้าทีมจะไม่เห็นหน้าทีมเลย", leading
            // straight to a screen that is now empty by design.
            'direct_reports_count' => $this->downlineService->isEnabled($user)
                ? $this->downlineService->directReports($user)->count()
                : 0,
            'task_counts' => $this->taskCounts($user),
            // TASK-180 §2 (A2) — the disclosure that keeps the sales goal
            // above honest, the personal twin of the dashboard's and the
            // sales-team card's `closed_deals_without_order` (D2): deals
            // this agent closed THIS MONTH that move the progress bar by
            // zero baht because no paid order exists for them. Never
            // estimated, never folded into actual_value. Deliberately
            // month-scoped, not all-time — the bar it qualifies is
            // month-to-date, and an all-time caveat next to a monthly
            // figure would itself be a number under a label describing a
            // different quantity, which is the exact defect TASK-179
            // exists to remove.
            'closed_deals_without_order_this_month' => $this->closedDealsWithoutOrderThisMonth($user, $monthStart),
            'unread_notifications' => (int) Notification::query()
                ->where('user_id', $user->id)->whereNull('read_at')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tasks(User $user): array
    {
        // Due/overdue client follow-ups (this agent's clients).
        $followUps = DB::table('client_activities')
            ->join('clients', 'clients.id', '=', 'client_activities.client_id')
            ->where('clients.referring_agent_id', $user->id)
            ->whereNotNull('client_activities.follow_up_at')
            ->where('client_activities.follow_up_at', '<=', now())
            ->orderBy('client_activities.follow_up_at')
            ->limit(30)
            ->get([
                'client_activities.id',
                'clients.id as client_id',
                'clients.name as client_name',
                'client_activities.summary',
                'client_activities.follow_up_at',
            ])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'client_id' => (int) $r->client_id,
                'client_name' => $r->client_name,
                'summary' => $r->summary,
                'follow_up_at' => $r->follow_up_at,
            ])
            ->all();

        // Open deals — everything this agent has NOT closed (TASK-180 A1).
        // The complement of ClosedDealPredicate, from that same class, so
        // "still to work" can never disagree with "sold". The hardcoded
        // [complete_payment, ongoing_next_meeting] list that used to live
        // here handed a paid deal sitting at จัดส่ง / ติดตามผล back to the
        // agent as an outstanding task.
        $openDealsQuery = DB::table('referrals')
            ->join('clients', 'clients.id', '=', 'referrals.client_id')
            ->leftJoin('products', 'products.id', '=', 'referrals.product_id')
            ->where('referrals.company_id', $user->company_id) // BR-6
            ->where('referrals.agent_id', $user->id);
        ClosedDealPredicate::applyOpen($openDealsQuery);
        $openDeals = $openDealsQuery
            ->orderByDesc('referrals.submitted_at')
            ->limit(30)
            ->get([
                'referrals.id',
                'clients.name as client_name',
                'products.name as product_name',
                'referrals.current_stage',
            ])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'client_name' => $r->client_name,
                'product_name' => $r->product_name,
                'stage_key' => $r->current_stage,
                'stage_label' => PipelineStage::tryFrom($r->current_stage)?->label() ?? $r->current_stage,
            ])
            ->all();

        // Exams attempted but never passed ("ยังไม่ผ่านเกณฑ์").
        $failedExamIds = DB::table('exam_attempts')
            ->where('user_id', $user->id)
            ->groupBy('exam_id')
            ->havingRaw('MAX(passed) = 0')
            ->pluck('exam_id');
        $failedExams = DB::table('exams')
            ->whereIn('id', $failedExamIds)
            ->get(['id', 'title', 'passing_score'])
            ->map(fn ($e) => [
                'id' => (int) $e->id,
                'title' => $e->title,
                'passing_score' => (int) $e->passing_score,
            ])
            ->all();

        return [
            'follow_ups' => $followUps,
            'open_deals' => $openDeals,
            'failed_exams' => $failedExams,
        ];
    }

    /**
     * @return array{follow_ups_due:int, open_deals:int, failed_exams:int}
     */
    private function taskCounts(User $user): array
    {
        $followUps = DB::table('client_activities')
            ->join('clients', 'clients.id', '=', 'client_activities.client_id')
            ->where('clients.referring_agent_id', $user->id)
            ->whereNotNull('client_activities.follow_up_at')
            ->where('client_activities.follow_up_at', '<=', now())
            ->count();

        // TASK-180 A1 — the badge behind tasks()' list, so it must ask the
        // same question the list answers: NOT closed, per the one shared
        // predicate. Two hardcoded copies of the same stage list in one
        // file is how a badge and its own list come to disagree.
        $openDealsQuery = DB::table('referrals')
            ->where('company_id', $user->company_id) // BR-6
            ->where('agent_id', $user->id);
        ClosedDealPredicate::applyOpen($openDealsQuery);
        $openDeals = $openDealsQuery->count();

        // pluck('exam_id') selects ONLY the grouped column — a bare
        // ->get() would SELECT * and break MySQL's only_full_group_by
        // (exam_attempts.id is nonaggregated under GROUP BY exam_id);
        // SQLite tolerates it, real MySQL 500s. Same group-by-safe shape
        // as tasks()' $failedExamIds below.
        $failedExams = DB::table('exam_attempts')
            ->where('user_id', $user->id)
            ->groupBy('exam_id')
            ->havingRaw('MAX(passed) = 0')
            ->pluck('exam_id')
            ->count();

        return [
            'follow_ups_due' => $followUps,
            'open_deals' => $openDeals,
            'failed_exams' => $failedExams,
        ];
    }

    /**
     * Month-to-date actuals per target metric, from this agent's PAID
     * ORDERS — money the CUSTOMER paid (TASK-179 D1/D2), bucketed on the
     * SALE date (D3), which for an order is its `paid_at`.
     *
     * TASK-180 §2 (A2). All three metrics used to be derived from
     * `commission_ledger` rows with `payment_status = paid` and
     * `earned_via = direct`, taking money from
     * `sale_price_satang_at_time ?? 0`. D2 rejected that source BY NAME and
     * on this screen it was at its worst: the ledger's payment_status is
     * when the COMPANY paid the AGENT, so an agent whose customer had paid
     * in full still watched a 0% progress ring until payroll ran. The
     * snapshot is also absent for pre-TASK-047 rows and absent entirely for
     * a deal closed while no commission rule was configured (
     * CommissionService::recordForReferral() writes no row at all) — and
     * `?? 0` turned every one of those into a silent zero. Same definition
     * as AgentDashboardMetricsService::totals() and
     * AgentSalesAggregateService, so an agent's own ยอดขาย and the one their
     * Admin sees for them can no longer be two different numbers.
     *
     * Deals and clients moved with the money deliberately: leaving them on
     * the ledger would put a count from the payroll axis next to money from
     * the sale axis in one card, which is finding F-2. One query, one axis,
     * three numbers.
     *
     * `orders.agent_id` is copied from the referral the order collects for,
     * and an order belongs to exactly one agent — so a sale is counted once
     * for the agent who made it, and never for their upline. That is what
     * the old `earned_via = direct` filter was protecting, preserved.
     *
     * A paid order with a NULL `paid_at` cannot be placed in a month at
     * all, so it is out of the month-to-date figure (same treatment the
     * dashboard's 6-month series gives it). OrderService always stamps
     * paid_at on confirmation, so this is a hand-edited-row guard.
     *
     * BR-3: `orders.amount_satang` is already integer satang — summed as
     * PHP ints, no division, no round(), no float on this path.
     *
     * @return array<string, int>
     */
    private function monthActuals(User $user, Carbon $monthStart): array
    {
        $orders = DB::table('orders')
            ->where('company_id', $user->company_id) // BR-6
            ->where('agent_id', $user->id)
            ->where('status', OrderStatus::Paid->value)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $monthStart)
            ->get(['referral_id', 'client_id', 'amount_satang']);

        $sales = 0;
        $referralIds = [];
        $clientIds = [];
        foreach ($orders as $order) {
            $sales += (int) $order->amount_satang;
            $referralIds[$order->referral_id] = true;
            $clientIds[$order->client_id] = true;
        }

        return [
            TargetMetric::SalesSatang->value => $sales,
            // Distinct, so two orders against one deal (or one client)
            // count once — the same "a sale counted once" property the
            // ledger version had.
            TargetMetric::Deals->value => count($referralIds),
            TargetMetric::Clients->value => count($clientIds),
        ];
    }

    /**
     * TASK-180 §2 (A2) disclosure, D2's principle applied to the agent's
     * own target bar: deals this agent CLOSED this month that contribute
     * zero baht to monthActuals() because no paid order exists for them.
     * Disclosed, never estimated, never folded into the actual.
     *
     * Two separate questions, answered by two separate things on purpose:
     *
     *   WHETHER it closed   ClosedDealPredicate, the one authority. This
     *                       is not a second closed-deal predicate — the
     *                       clause below never decides closedness.
     *   WHEN it closed      the `to_stage = complete_payment` stage log's
     *                       changed_at. A dated volume question, which the
     *                       boolean predicate deliberately cannot express
     *                       (TASK-180 §4 records the same shape as correct
     *                       in StairstepCommissionService).
     *
     * Month-scoped because the bar it qualifies is month-to-date. A deal
     * that closed last quarter without an order does not make THIS month's
     * figure short, and reporting it here would be an all-time number under
     * a month-to-date label — the exact class of defect TASK-179 exists to
     * remove.
     *
     * A closed deal with no stage log has no close DATE at all, so it
     * cannot be placed in a month and is absent here (while still counting
     * as closed everywhere the question is undated). That is the
     * fixture/import asymmetry TASK-180 §5 puts out of scope; it is not
     * widened here.
     */
    private function closedDealsWithoutOrderThisMonth(User $user, Carbon $monthStart): int
    {
        $query = DB::table('referrals')
            ->where('referrals.company_id', $user->company_id) // BR-6
            ->where('referrals.agent_id', $user->id)
            ->whereExists(function (QueryBuilder $sub) use ($monthStart) {
                $sub->select(DB::raw('1'))
                    ->from('pipeline_stage_logs')
                    ->whereColumn('pipeline_stage_logs.referral_id', 'referrals.id')
                    ->where('pipeline_stage_logs.to_stage', PipelineStage::CompletePayment->value)
                    ->where('pipeline_stage_logs.changed_at', '>=', $monthStart);
            })
            // "No paid order" is asked of the REFERRAL, the same way the
            // dashboard and the sales-team card ask it, so a deal is never
            // called uncounted while an order that really did pay sits
            // against it.
            ->whereNotExists(function (QueryBuilder $sub) {
                $sub->select(DB::raw('1'))
                    ->from('orders')
                    ->whereColumn('orders.referral_id', 'referrals.id')
                    ->where('orders.status', OrderStatus::Paid->value);
            });

        ClosedDealPredicate::apply($query);

        return (int) $query->count();
    }
}
