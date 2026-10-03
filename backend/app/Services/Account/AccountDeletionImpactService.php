<?php

namespace App\Services\Account;

use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * MOB-12 (2026-10-02) — "what is still attached to this person?", for the
 * admin deciding an account deletion request.
 *
 * Three numbers, each a thing the admin is expected to deal with BEFORE
 * approving, using screens that already exist:
 *
 *   pending_commission_satang — commission this agent has earned and not been
 *       paid: SUM(amount_satang) over their commission_ledger rows whose
 *       payment_status is still `pending` (BR-4: the status is the one mutable
 *       field; the amounts are read as written, never recomputed). Reversals
 *       carry the original's status and a negative amount
 *       (CommissionReversalService), so the sum is already net of refunds.
 *       It deliberately does NOT subtract what an open payout has reserved
 *       (CommissionWithdrawalService::availableSatang does): reserved money is
 *       still unpaid money, and "settle it first" covers both. Integer satang
 *       throughout (BR-3).
 *   downline_count — agents whose upline (users.manager_id) is this person,
 *       i.e. User::directReports(), the same relation the roster's removal
 *       check (AccountActivityProbe::DOWNLINE) counts. Deactivated agents are
 *       excluded by the soft-delete scope: nobody needs re-homing who is no
 *       longer active.
 *   client_count — clients whose referring agent is this person
 *       (clients.referring_agent_id, User::referredClients()). Soft-deleted
 *       clients excluded for the same reason.
 *
 * ── INFORMATIONAL ONLY, BY DECISION ──
 *
 * The owner did not ask for approval to be blocked by any of these, so
 * nothing here refuses anything — the numbers are returned and the screen
 * shows them as warnings. If that changes, this is the one place that knows
 * how to count them.
 *
 * ── TENANCY ──
 *
 * All three models carry TenantScope, so a Company Admin's counts are their
 * own company's rows only — which is also exactly the set they can act on.
 * A Super Admin counts across companies, which matters only for an agent
 * moved between companies, where a cross-company figure is the honest one.
 *
 * Batched: one grouped query per number for a whole page of requests, never
 * three queries per row.
 */
class AccountDeletionImpactService
{
    /**
     * Keyed by user id. Every requested id is present, with zeros when
     * nothing is attached — a missing key must never read as "unknown".
     *
     * @param  iterable<int>  $userIds
     * @return Collection<int, array{pending_commission_satang: int, downline_count: int, client_count: int}>
     */
    public function forUsers(iterable $userIds): Collection
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $pending = CommissionLedger::query()
            ->whereIn('agent_id', $ids)
            ->where('payment_status', PaymentStatus::Pending->value)
            ->groupBy('agent_id')
            ->selectRaw('agent_id, SUM(amount_satang) as total')
            ->pluck('total', 'agent_id');

        $downline = User::query()
            ->whereIn('manager_id', $ids)
            ->groupBy('manager_id')
            ->selectRaw('manager_id, COUNT(*) as total')
            ->pluck('total', 'manager_id');

        $clients = Client::query()
            ->whereIn('referring_agent_id', $ids)
            ->groupBy('referring_agent_id')
            ->selectRaw('referring_agent_id, COUNT(*) as total')
            ->pluck('total', 'referring_agent_id');

        return $ids->mapWithKeys(fn (int $id) => [$id => [
            'pending_commission_satang' => (int) ($pending[$id] ?? 0),
            'downline_count' => (int) ($downline[$id] ?? 0),
            'client_count' => (int) ($clients[$id] ?? 0),
        ]]);
    }

    /**
     * @return array{pending_commission_satang: int, downline_count: int, client_count: int}
     */
    public function forUser(User $user): array
    {
        return $this->forUsers([$user->id])->get($user->id);
    }
}
