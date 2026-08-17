<?php

namespace App\Services\Academy;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * TASK-152 — the Academy progress readout, aggregated SERVER-SIDE.
 *
 * WHY THIS CLASS EXISTS (the bug it replaces)
 * -------------------------------------------
 * `frontend-admin/src/views/AcademyManagementView.vue`'s ความคืบหน้าตัวแทน tab
 * built every "X/Y บทเรียน" in the browser by joining three separately
 * PAGINATED endpoints:
 *
 *   GET /modules            → 15 Sections per page  (the Y)
 *   GET /module-completions → 15 completions per page (the X)
 *   GET /user-certifications→ 15 rows per page      (the cert badges)
 *
 * Only `/users` was ever read past page 1 (its own `fetchAllPages`). So on any
 * company with more than 15 Sections, or more than 15 completions IN TOTAL
 * across all agents — which is essentially every real company — the numerator
 * and the denominator were both silently truncated, and the fraction on screen
 * was fiction. Those fractions are how a Company Admin decides whether an
 * agent is progressing toward the Basic certification that unlocks selling
 * rights (BR-1), so a wrong number there is not cosmetic.
 *
 * Fixing it client-side would mean following every page of three endpoints:
 * N requests to answer one question, and still wrong the moment a page size
 * changes. Every count below is therefore a SQL aggregate. Nothing that can be
 * paginated participates in the arithmetic.
 *
 * THE DENOMINATOR (ADR-031 §2.4)
 * ------------------------------
 * `required_lesson_count` = lessons that are PUBLISHED and NOT optional, which
 * is exactly how `ModuleResource` computes the field of the same name. Drafts
 * and optional lessons never appear in a denominator: an optional lesson
 * counted would leave a learner stuck at "4/5" forever, which is the precise
 * failure ADR-031 §2.4 was written to prevent.
 *
 * TENANT SCOPING — READ BEFORE EDITING (BR-6, CLAUDE.md §5)
 * ---------------------------------------------------------
 * Everything below is the QUERY BUILDER, not Eloquent, so `TenantScope` does
 * NOT apply. Every table in every query therefore carries its own explicit
 * `company_id = ?` predicate, including the ones a naive reading would call
 * redundant (a lesson's company must equal its module's company). They are not
 * redundant: they are the only thing standing between this endpoint and a
 * cross-tenant read, and a future migration that adds a nullable FK must not be
 * able to open one. The caller resolves `$companyId` — a Company Admin can only
 * ever be handed their own (see AcademyProgressSummaryRequest).
 */
class AcademyProgressSummaryService
{
    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>, summary: array<string, mixed>}
     */
    public function build(int $companyId, ?string $search = null, int $perPage = 25): array
    {
        $catalogue = $this->sectionCatalogue($companyId);
        $companyRequiredTotal = array_sum(array_column($catalogue, 'required_lesson_count'));

        $agents = $this->agentPage($companyId, $search, $perPage);
        $userIds = $agents->getCollection()->pluck('id')->all();

        return [
            'data' => $this->agentRows($companyId, $agents, $catalogue, $companyRequiredTotal, $userIds),
            'meta' => [
                'current_page' => $agents->currentPage(),
                'last_page' => $agents->lastPage(),
                'per_page' => $agents->perPage(),
                'total' => $agents->total(),
            ],
            'summary' => $this->summary($companyId, $catalogue, $companyRequiredTotal),
        ];
    }

    // =================================================================
    // The list of PEOPLE — the only thing here that may be truncated
    // =================================================================

    /**
     * Paginating a list of people is honest: the admin asked for a screenful of
     * agents and gets a screenful of agents, with a `meta` block saying how many
     * more there are. Paginating the LESSONS underneath them would not be — that
     * silently changes an answer rather than shortening a list.
     *
     * Sourced from `users`, NOT from `module_completions`. That is what makes
     * "a learner with zero completions appears with 0/N rather than vanishing"
     * structurally true instead of a special case someone can delete later: an
     * agent who has never opened a lesson has no completion rows at all, so any
     * roster derived from completions would omit exactly the people an admin
     * opens this screen to find.
     *
     * @return LengthAwarePaginator<int, User>
     */
    private function agentPage(int $companyId, ?string $search, int $perPage): LengthAwarePaginator
    {
        return User::query()
            ->where('company_id', $companyId)
            ->where('role', UserRole::Agent->value)
            // Free-text search over name/phone/email only, mirroring
            // UserController::index()'s `q`. national_id is deliberately NOT
            // searchable here and is NOT returned: it is encrypted at rest and
            // its reveal gate lives in UserResource (PDPA, CLAUDE.md §6). An
            // aggregate progress endpoint is not the place to grow a second
            // copy of that gate — national-ID lookup stays on /users.
            ->when($search !== null && $search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, ['id', 'first_name', 'last_name', 'name']);
    }

    /**
     * @param  LengthAwarePaginator<int, User>  $agents
     * @param  array<int, array<string, mixed>>  $catalogue
     * @param  array<int, int>  $userIds
     * @return array<int, array<string, mixed>>
     */
    private function agentRows(
        int $companyId,
        LengthAwarePaginator $agents,
        array $catalogue,
        int $companyRequiredTotal,
        array $userIds,
    ): array {
        $perSection = $this->requiredCompletionsPerAgentPerSection($companyId, $userIds);
        $optionalCounts = $this->optionalCompletionsPerAgent($companyId, $userIds);
        $completedLessonIds = $this->completedLessonIdsPerAgent($companyId, $userIds);
        $certTiers = $this->certTiersPassedPerAgent($companyId, $userIds);

        $rows = [];

        foreach ($agents->items() as $agent) {
            $sections = [];
            $completedRequired = 0;

            // A row for EVERY Section, not only the ones this agent has touched
            // — same reason the roster comes from `users`: a Section the agent
            // has done nothing in is the row an admin is looking for, and
            // omitting it would read as "no such Section" rather than "0/4".
            foreach ($catalogue as $section) {
                $completed = $perSection[$agent->id][$section['id']] ?? 0;
                $completedRequired += $completed;

                $sections[] = [
                    'module_id' => $section['id'],
                    'required_lesson_count' => $section['required_lesson_count'],
                    'completed_required_count' => $completed,
                ];
            }

            $rows[] = [
                'user_id' => $agent->id,
                'name' => $agent->name,
                'first_name' => $agent->first_name,
                'last_name' => $agent->last_name,

                // The company-wide denominator, repeated on every row so the row
                // is self-describing: whoever renders it never has to reach for
                // a second (paginated) call to find its own Y.
                'required_lesson_count' => $companyRequiredTotal,
                // Summed from the per-Section aggregates immediately above
                // rather than re-queried. The two are the same rows partitioned
                // two ways (identical joins, identical predicates), so a second
                // GROUP BY could only ever disagree by drifting away from this
                // one — the sum cannot.
                'completed_required_count' => $completedRequired,
                /*
                 * COMPLETIONS ON A LESSON THAT IS NOW OPTIONAL — the decision.
                 *
                 * An admin marks a lesson optional AFTER agents have completed
                 * it. Those `module_completions` rows still exist (they are
                 * append-only and grandfathered — ADR-028 §2.3, and this class
                 * never re-evaluates one). The question is which side of the
                 * fraction they land on.
                 *
                 * They are excluded from `completed_required_count`, because
                 * the numerator MUST range over the same lesson set as the
                 * denominator. Counting them there would print "6/5", and a
                 * fraction above 1 tells an admin the dashboard is broken —
                 * which would then be true.
                 *
                 * They are not thrown away either: dropping them silently would
                 * make an agent's total appear to fall overnight for a reason no
                 * one on the screen could see. They are reported here, beside
                 * the required fraction, so "5/5 (+2 บทเสริม)" is expressible.
                 * Same treatment for a completion on a lesson later UNPUBLISHED
                 * or soft-deleted, with one difference: those are excluded from
                 * both counts, because the lesson is no longer part of the
                 * course at all and there is nowhere honest to show it.
                 */
                'completed_optional_count' => $optionalCounts[$agent->id] ?? 0,
                // Per-lesson ticks for the expanded row. A complete set for this
                // agent — never a page of one.
                'completed_lesson_ids' => $completedLessonIds[$agent->id] ?? [],
                // On the same screen row as the fraction, and truncated by the
                // same bug: /user-certifications paginates at 15 for the whole
                // company, so an agent's cert badges could silently disappear.
                // Complete per agent here.
                'cert_tiers_passed' => $certTiers[$agent->id] ?? [],
                'sections' => $sections,
            ];
        }

        return $rows;
    }

    // =================================================================
    // The course outline + its denominators
    // =================================================================

    /**
     * Every Section in the company with its three lesson counts, computed with
     * SUM(CASE...) over a LEFT JOIN so a Section with no lessons at all still
     * comes back (as 0), rather than dropping out of the outline.
     *
     * A draft SECTION is still reported (the admin outline must show what is
     * being drafted, and `is_published` travels with it) but contributes ZERO
     * to both counts — see the `m.is_published = 1` term in each SUM below.
     *
     * TASK-155 / ADR-031 §4 (ag-lead ruling, 2026-08-10) — this resolves the
     * `TODO: CONFIRM` that stood here. It is NOT a BR-7 business value: it
     * follows from what the flag already means. ADR-031 §2.4 settled it for
     * LESSONS ("a learner who skips an optional lesson would see 4/5 forever
     * and reasonably conclude the system is broken") and the identical
     * argument applies one level up — an admin drafting next quarter's
     * material would otherwise drag every agent's percentage down the moment
     * they created the Section.
     *
     * The old comment justified including drafts on the grounds that this
     * endpoint had to agree with /modules, which shipped them. That premise is
     * gone: TASK-155 filters drafts out of /modules for Agents, because they
     * were never supposed to be visible in the first place. Both denominators
     * now say the same thing for the same reason.
     *
     * DENOMINATOR AND NUMERATOR MOVE TOGETHER. Every completion query below
     * carries the same `is_published` term on the Section (see
     * countableCompletions(), completedLessonIdsPerAgent() and the
     * `$requiredPerSection` subquery). Adding it in only one of the two places
     * would let an agent's numerator exceed their denominator, which is the
     * exact class of bug this screen was already suffering from.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sectionCatalogue(int $companyId): array
    {
        $sections = DB::table('modules as m')
            ->leftJoin('module_lessons as ml', function ($join) use ($companyId) {
                $join->on('ml.module_id', '=', 'm.id')
                    ->whereNull('ml.deleted_at')
                    ->where('ml.company_id', '=', $companyId);
            })
            ->where('m.company_id', $companyId)
            ->whereNull('m.deleted_at')
            ->groupBy('m.id', 'm.title', 'm.sort_order', 'm.cert_tier_id', 'm.is_published')
            ->orderBy('m.sort_order')
            ->orderBy('m.id')
            ->select([
                'm.id',
                'm.title',
                'm.sort_order',
                'm.cert_tier_id',
                'm.is_published',
                DB::raw('COUNT(ml.id) as lesson_count'),
                // ADR-031 §2.4 — THE denominator. Published AND not optional.
                // TASK-155 — and inside a PUBLISHED Section: a draft Section's
                // lessons are not part of the course, so they are not part of
                // anyone's fraction. The Section row itself still comes back,
                // with these counts at 0.
                DB::raw('SUM(CASE WHEN ml.id IS NOT NULL AND m.is_published = 1 AND ml.is_published = 1 AND ml.is_optional = 0 THEN 1 ELSE 0 END) as required_lesson_count'),
                DB::raw('SUM(CASE WHEN ml.id IS NOT NULL AND m.is_published = 1 AND ml.is_published = 1 AND ml.is_optional = 1 THEN 1 ELSE 0 END) as optional_lesson_count'),
            ])
            ->get();

        $lessonsBySection = $this->lessonOutline($companyId);

        return $sections->map(fn ($row) => [
            'id' => (int) $row->id,
            'title' => $row->title,
            'sort_order' => (int) $row->sort_order,
            'cert_tier_id' => (int) $row->cert_tier_id,
            'is_published' => (bool) $row->is_published,
            'lesson_count' => (int) $row->lesson_count,
            'required_lesson_count' => (int) $row->required_lesson_count,
            'optional_lesson_count' => (int) $row->optional_lesson_count,
            'lessons' => $lessonsBySection[(int) $row->id] ?? [],
        ])->all();
    }

    /**
     * The lesson titles the expanded agent row ticks off, shipped ONCE for the
     * whole company rather than repeated under every agent.
     *
     * Included because the outline was the other half of the same truncation:
     * the screen reads it from GET /modules, which paginates at 15 Sections, so
     * a course with a sixteenth Section simply stopped being drawn.
     *
     * Drafts are included and flagged (`is_published`) rather than hidden, so an
     * admin can see the lesson they have not published yet; they are already
     * excluded from `required_lesson_count` above, which is where it matters.
     *
     * @return array<int, array<int, array<string, mixed>>>  keyed by module_id
     */
    private function lessonOutline(int $companyId): array
    {
        return DB::table('module_lessons as ml')
            ->join('modules as m', 'm.id', '=', 'ml.module_id')
            ->where('ml.company_id', $companyId)
            ->where('m.company_id', $companyId)
            ->whereNull('ml.deleted_at')
            ->whereNull('m.deleted_at')
            ->orderBy('ml.module_id')
            ->orderBy('ml.sort_order')
            ->orderBy('ml.id')
            ->select(['ml.id', 'ml.module_id', 'ml.title', 'ml.sort_order', 'ml.is_optional', 'ml.is_published'])
            ->get()
            ->groupBy('module_id')
            ->map(fn ($lessons) => $lessons->map(fn ($lesson) => [
                'id' => (int) $lesson->id,
                'title' => $lesson->title,
                'sort_order' => (int) $lesson->sort_order,
                'is_optional' => (bool) $lesson->is_optional,
                'is_published' => (bool) $lesson->is_published,
            ])->values()->all())
            ->all();
    }

    // =================================================================
    // The aggregates
    // =================================================================

    /**
     * The one place the "which completions count" rule is written down. Every
     * aggregate in this class is built on top of it, so the numerator and the
     * denominator cannot drift apart by someone editing one query and missing
     * another.
     *
     * `module_completions` has a UNIQUE (user_id, module_lesson_id), so COUNT(*)
     * over this join is already a count of distinct lessons — no COUNT(DISTINCT)
     * needed, and none should be added on the assumption that it is safer.
     *
     * Grandfathered completions (ADR-028 §2.3 guard 1, extended by ADR-029 §3
     * and ADR-031) count exactly like any other: they are ordinary rows in
     * `module_completions` and nothing here re-checks whether they would satisfy
     * today's gate. Re-evaluating them would be the same mistake the write path
     * was careful not to make.
     */
    private function countableCompletions(int $companyId, bool $optional): QueryBuilder
    {
        return DB::table('module_completions as mc')
            ->join('module_lessons as ml', 'ml.id', '=', 'mc.module_lesson_id')
            ->join('modules as m', 'm.id', '=', 'ml.module_id')
            ->join('users as u', 'u.id', '=', 'mc.user_id')
            // BR-6 — four explicit company predicates, one per joined table.
            // See the class docblock: TenantScope does not reach the query
            // builder, so these ARE the isolation.
            ->where('mc.company_id', $companyId)
            ->where('ml.company_id', $companyId)
            ->where('m.company_id', $companyId)
            ->where('u.company_id', $companyId)
            ->where('u.role', UserRole::Agent->value)
            ->whereNull('u.deleted_at')
            ->whereNull('ml.deleted_at')
            ->whereNull('m.deleted_at')
            // ADR-031 §2.4.
            ->where('ml.is_published', true)
            // TASK-155 — the numerator must carry the same Section-level
            // publish term as sectionCatalogue()'s denominator, or an agent
            // who finished a lesson before its Section was unpublished would
            // count against a denominator that no longer includes it.
            ->where('m.is_published', true)
            ->where('ml.is_optional', $optional);
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, array<int, int>>  [user_id][module_id] => completed
     */
    private function requiredCompletionsPerAgentPerSection(int $companyId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->countableCompletions($companyId, optional: false)
            ->whereIn('mc.user_id', $userIds)
            ->groupBy('mc.user_id', 'ml.module_id')
            ->select(['mc.user_id', 'ml.module_id', DB::raw('COUNT(*) as completed')])
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->user_id][(int) $row->module_id] = (int) $row->completed;
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    private function optionalCompletionsPerAgent(int $companyId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        // NOT ->pluck('completed', 'user_id'): pluck() rewrites the SELECT list
        // to just the two columns it is given, which would drop the COUNT(*)
        // alias this depends on and ask the database for a column called
        // "completed" that does not exist. get() + a loop, like the sibling
        // aggregates above.
        $rows = $this->countableCompletions($companyId, optional: true)
            ->whereIn('mc.user_id', $userIds)
            ->groupBy('mc.user_id')
            ->select(['mc.user_id', DB::raw('COUNT(*) as completed')])
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->user_id] = (int) $row->completed;
        }

        return $map;
    }

    /**
     * Required AND optional together — these drive the per-lesson ticks in the
     * expanded row, which show the whole outline, not just the graded part.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array<int, int>>
     */
    private function completedLessonIdsPerAgent(int $companyId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        // Same universe as countableCompletions() minus the is_optional
        // predicate; written out rather than parameterised because a nullable
        // "either" flag on that method would make the numerator's rule harder
        // to read than it is worth.
        $rows = DB::table('module_completions as mc')
            ->join('module_lessons as ml', 'ml.id', '=', 'mc.module_lesson_id')
            ->join('modules as m', 'm.id', '=', 'ml.module_id')
            ->where('mc.company_id', $companyId)
            ->where('ml.company_id', $companyId)
            ->where('m.company_id', $companyId)
            ->whereNull('ml.deleted_at')
            ->whereNull('m.deleted_at')
            ->where('ml.is_published', true)
            // TASK-155 — same Section-level publish term as the denominator.
            // These are the per-lesson ticks in the expanded row; a tick
            // against a lesson the fraction no longer counts reads as the
            // screen contradicting itself.
            ->where('m.is_published', true)
            ->whereIn('mc.user_id', $userIds)
            ->orderBy('mc.user_id')
            ->orderBy('mc.module_lesson_id')
            ->select(['mc.user_id', 'mc.module_lesson_id'])
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->user_id][] = (int) $row->module_lesson_id;
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function certTiersPassedPerAgent(int $companyId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        // cert_tiers is GLOBAL config (no company_id column, no TenantScope);
        // user_certifications is the tenant-scoped side and carries the guard.
        $rows = DB::table('user_certifications as uc')
            ->join('cert_tiers as ct', 'ct.id', '=', 'uc.cert_tier_id')
            ->where('uc.company_id', $companyId)
            ->whereIn('uc.user_id', $userIds)
            ->orderBy('ct.sort_order')
            ->orderBy('ct.id')
            ->select(['uc.user_id', 'uc.passed_at', 'ct.id', 'ct.key', 'ct.name'])
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->user_id][] = [
                'id' => (int) $row->id,
                'key' => $row->key,
                'name' => $row->name,
                'passed_at' => $row->passed_at,
            ];
        }

        return $map;
    }

    // =================================================================
    // The company-wide roll-up
    // =================================================================

    /**
     * Per Section ACROSS agents — "how many of my 40 agents have actually
     * finished this Section".
     *
     * Deliberately NOT narrowed by the `q` search box and NOT limited to the
     * current page: it describes the company, so it must not move while an
     * admin types a name. `agent_count` below is the population it is measured
     * against, for the same reason.
     *
     * @param  array<int, array<string, mixed>>  $catalogue
     * @return array<string, mixed>
     */
    private function summary(int $companyId, array $catalogue, int $companyRequiredTotal): array
    {
        $agentCount = User::query()
            ->where('company_id', $companyId)
            ->where('role', UserRole::Agent->value)
            ->count();

        $rollup = $this->sectionRollup($companyId);

        $sections = array_map(function (array $section) use ($rollup, $agentCount) {
            $stats = $rollup[$section['id']] ?? ['agents_completed' => 0, 'completed_required_total' => 0];

            return $section + [
                /*
                 * Agents who have completed EVERY required lesson in this
                 * Section.
                 *
                 * A Section with no required lessons is vacuously complete for
                 * everyone, so it reports the full agent count rather than 0.
                 * That is arithmetic, not a business rule: the per-agent row for
                 * the same Section reads 0/0, and an empty Section reporting
                 * "0 of 40 agents finished" beside a 0/0 would be the dashboard
                 * contradicting itself.
                 */
                'agents_completed' => $section['required_lesson_count'] === 0
                    ? $agentCount
                    : $stats['agents_completed'],
                'completed_required_total' => $stats['completed_required_total'],
            ];
        }, $catalogue);

        return [
            'company_id' => $companyId,
            // Company-wide; the paginator's `meta.total` is the SEARCH-filtered
            // count and the two are allowed to differ.
            'agent_count' => $agentCount,
            'required_lesson_count' => $companyRequiredTotal,
            'sections' => $sections,
        ];
    }

    /**
     * Two levels of GROUP BY in SQL, joined as subqueries — no intermediate row
     * set is ever pulled into PHP.
     *
     * The inner query counts each agent's required completions per Section
     * (#agents x #sections rows, which for a large company is exactly the set we
     * must not materialise). The outer query joins it against each Section's
     * required-lesson count and collapses it: SUM for the raw total, SUM(CASE)
     * for how many agents cleared the bar.
     *
     * @return array<int, array{agents_completed: int, completed_required_total: int}>
     */
    private function sectionRollup(int $companyId): array
    {
        $perAgentPerSection = $this->countableCompletions($companyId, optional: false)
            ->groupBy('mc.user_id', 'ml.module_id')
            ->select(['mc.user_id', 'ml.module_id', DB::raw('COUNT(*) as completed')]);

        $requiredPerSection = DB::table('module_lessons as rl')
            ->join('modules as rm', 'rm.id', '=', 'rl.module_id')
            ->where('rl.company_id', $companyId)
            ->where('rm.company_id', $companyId)
            ->whereNull('rl.deleted_at')
            ->whereNull('rm.deleted_at')
            ->where('rl.is_published', true)
            // TASK-155 — as above. This is the per-Section denominator the
            // "agents who finished this Section" rollup divides by.
            ->where('rm.is_published', true)
            ->where('rl.is_optional', false)
            ->groupBy('rl.module_id')
            // `required_count`, not `required`: this alias is referenced from a
            // DB::raw() expression below, where nothing quotes it for us.
            ->select(['rl.module_id', DB::raw('COUNT(*) as required_count')]);

        $rows = DB::query()
            ->fromSub($perAgentPerSection, 'pas')
            ->joinSub($requiredPerSection, 'req', 'req.module_id', '=', 'pas.module_id')
            ->groupBy('pas.module_id')
            ->select([
                'pas.module_id',
                DB::raw('SUM(pas.completed) as completed_required_total'),
                DB::raw('SUM(CASE WHEN pas.completed >= req.required_count THEN 1 ELSE 0 END) as agents_completed'),
            ])
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->module_id] = [
                'agents_completed' => (int) $row->agents_completed,
                'completed_required_total' => (int) $row->completed_required_total,
            ];
        }

        return $map;
    }
}
