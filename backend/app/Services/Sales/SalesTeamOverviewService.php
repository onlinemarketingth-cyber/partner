<?php

namespace App\Services\Sales;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-050 / ADR-014 — "ทีมขาย" leadership cockpit (Manager rollup).
 *
 * Pure read/aggregation: one row per Agent in the company, carrying the
 * agent's own manager_id (so the frontend can assemble the hierarchy
 * tree — หัวหน้าทีม → downline), plus per-agent sales KPIs: distinct
 * client count, deal count per pipeline stage (§4.3), total/closed deals
 * and a conversion %. Reports over existing referrals rows only — never
 * writes, never recomputes commission (BR-4 untouched).
 *
 * Tenant scoping (§5): the agent list is filtered by company_id, and the
 * referral aggregation is keyed off THOSE company-scoped agent ids only,
 * so a Company Admin can never see another company's team. $companyId is
 * threaded explicitly (never trusted from the client — the Controller
 * only ever passes a confirmed Super Admin's own ?company_id, or the
 * Company Admin's own company_id), mirroring AgentCommissionSummaryService.
 *
 * TASK-107 — the per-agent counting/money queries moved verbatim into
 * AgentSalesAggregateService so the Agent Portal team monitor reports the
 * SAME numbers as this cockpit. The output shape of build() is deliberately
 * unchanged (same keys, same values, same conversion rounding) — the admin
 * cockpit depends on it.
 */
class SalesTeamOverviewService
{
    public function __construct(private readonly AgentSalesAggregateService $aggregate) {}

    /**
     * The per-agent rows only. Unchanged shape — every existing caller and
     * test reads this.
     *
     * @return Collection<int, array{agent_id:int, agent_name:?string, manager_id:?int, is_team_leader:bool, avatar_url:?string, client_count:int, deals_by_stage:array<string,int>, total_deals:int, closed_deals:int, conversion:float, agent_approval_status:?string, approval_rejection_reason:?string, approval_source:?string, approved_by:?array{id:int,name:?string,is_team_leader:bool}, approved_at:?string}>
     */
    public function build(User $actor, ?int $companyId = null): Collection
    {
        return $this->buildWithTotals($actor, $companyId)['agents'];
    }

    /**
     * TASK-179 §3.6 (F-15) — the rows PLUS the company-level figures that
     * cannot be derived by adding the rows up.
     *
     * `clients_total` is a true COUNT(DISTINCT referrals.client_id) across
     * the whole team. The header KPI used to SUM the per-agent
     * client_counts, which double-counts any client referred by two agents
     * — a first-class scenario in this product (TASK-049 exists precisely
     * because one client can appear under several agents), so the header
     * could read more clients than the company has. Summing the cards can
     * never produce this number; it has to come from the database.
     *
     * @return array{agents: Collection<int, array<string, mixed>>, clients_total: int}
     */
    public function buildWithTotals(User $actor, ?int $companyId = null): array
    {
        $scopedCompanyId = $actor->isSuperAdmin() ? $companyId : $actor->company_id;

        $agentsQuery = User::query()->where('role', UserRole::Agent->value);
        if ($scopedCompanyId !== null) {
            $agentsQuery->where('company_id', $scopedCompanyId);
        }
        /** @var Collection<int, User> $agents */
        // TASK-203 — buildWithTotals() has ALWAYS included pending/rejected
        // agents alongside approved ones (no agent_approval_status filter
        // above, deliberately unchanged by this task); what was missing was
        // any way to tell them apart once they landed on a card. `with(
        // 'approvedBy')` + the five extra columns below let the response
        // carry the SAME shape UserResource already exposes for the
        // approval-queue screen (agent_approval_status /
        // approval_rejection_reason / approval_source / approved_by /
        // approved_at), so the frontend's existing approvalSourceChip() /
        // approvalProvenance() rendering works unmodified against this row
        // shape too.
        $agents = $agentsQuery
            ->with('approvedBy')
            ->get([
                'id', 'name', 'email', 'phone', 'avatar_path', 'manager_id', 'is_team_leader',
                'agent_approval_status', 'approval_rejection_reason', 'approval_source',
                'approved_by_user_id', 'approved_at',
            ]);
        $agentIds = $agents->pluck('id');

        // TASK-107 — the per-agent counting/money queries now live in
        // AgentSalesAggregateService (extracted verbatim from here) so the
        // Agent Portal team monitor and this cockpit cannot report different
        // numbers for the same agent. Same three queries, same paid-only
        // money semantics, same all-five-stages shape — see that Service.
        $rollup = $this->aggregate->forAgents($agentIds);

        // BR-6: keyed off the SAME company-scoped agent id whitelist the
        // per-agent rollup uses, plus the company_id filter itself — this
        // never widens what the actor can see, it only de-duplicates it.
        $clientsTotal = $agentIds->isEmpty()
            ? 0
            : (int) DB::table('referrals')
                ->whereIn('agent_id', $agentIds)
                ->when($scopedCompanyId !== null, fn ($q) => $q->where('company_id', $scopedCompanyId))
                ->distinct()
                ->count('client_id');

        $rows = $agents->map(function (User $agent) use ($rollup) {
            // An agent with no referrals/ledger rows at all is absent from
            // the rollup — never a missing key in the response.
            $row = $rollup[(int) $agent->id] ?? AgentSalesAggregateService::emptyRow();

            return [
                'agent_id' => (int) $agent->id,
                'agent_name' => $agent->name,
                'agent_email' => $agent->email,
                'agent_phone' => $agent->phone,
                'manager_id' => $agent->manager_id !== null ? (int) $agent->manager_id : null,
                // TASK-125 / ADR-025 §1–§2 — the admin-granted CAPABILITY
                // flag, exposed alongside manager_id so the Admin cockpit can
                // split "หัวหน้าทีม" from "ตัวแทนอิสระ". Deliberately NOT the
                // same thing as "has direct reports": ADR-025 §2 keeps the two
                // apart (a designated leader may have recruited nobody yet; an
                // agent may have reports without ever being granted the flag).
                // Both facts therefore travel to the frontend separately.
                'is_team_leader' => (bool) $agent->is_team_leader,
                'avatar_url' => $agent->avatar_path
                    ? Storage::disk('public')->url($agent->avatar_path)
                    : null,
                'client_count' => $row['client_count'],
                'deals_by_stage' => $row['deals_by_stage'],
                'total_deals' => $row['total_deals'],
                'closed_deals' => $row['closed_deals'],
                'conversion' => $row['total_deals'] > 0
                    ? round($row['closed_deals'] / $row['total_deals'] * 100, 1)
                    : 0.0,
                // TASK-179 (D1/D2) — `total_sales_satang` is MONEY THE
                // CUSTOMER PAID: this agent's paid orders, the same
                // definition and the same source the dashboard's
                // `sales_paid_satang` uses. It used to be the commission
                // ledger's sale-price snapshot gated on the COMMISSION's
                // payment_status, so this screen and the dashboard showed
                // two different "ยอดขาย" for one company — see
                // AgentSalesAggregateService's docblock. `total_commission_
                // satang` is the other axis (money disbursed to the agent)
                // and is unchanged. Key names kept: the frontend reads them.
                // BR-3 — both integer satang.
                'total_sales_satang' => $row['sales_satang'],
                'total_commission_satang' => $row['commission_satang'],
                // The disclosure that keeps the figure above honest (D2):
                // this agent's closed deals with no paid order, contributing
                // zero baht. The card states it as a sentence when > 0.
                'closed_deals_without_order' => $row['closed_deals_without_order'],
                // TASK-203 — same value semantics as UserResource's toArray()
                // (see its docblock, ~line 91-120): `agent_approval_status`
                // is the enum string ('pending'|'approved'|'rejected'),
                // `approval_source`/`approved_by`/`approved_at` are all null
                // together for every row approved before the TASK-115
                // migration or created directly by an Admin (never guess an
                // approver). `approved_by` is built from the SAME loaded
                // relation UserResource reads via whenLoaded — deliberately
                // not gated behind can('view') here, unlike the bank/national
                // ID fields on UserResource: this is provenance of an
                // approval action (who let this agent in), not PDPA data.
                'agent_approval_status' => $agent->agent_approval_status?->value,
                'approval_rejection_reason' => $agent->approval_rejection_reason,
                'approval_source' => $agent->approval_source?->value,
                'approved_by' => $agent->approvedBy ? [
                    'id' => (int) $agent->approvedBy->id,
                    'name' => $agent->approvedBy->name,
                    'is_team_leader' => (bool) $agent->approvedBy->is_team_leader,
                ] : null,
                'approved_at' => $agent->approved_at,
            ];
        })->values();

        return ['agents' => $rows, 'clients_total' => $clientsTotal];
    }
}
