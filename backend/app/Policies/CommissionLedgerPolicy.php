<?php

namespace App\Policies;

use App\Models\CommissionLedger;
use App\Models\User;

// Different visibility shape than CommissionRulePolicy on purpose:
// CommissionRule is company-wide CONFIG that only Company Admin/Super
// Admin may read at all — but CommissionLedger is an Agent's own
// EARNINGS record, so an Agent must be able to see their own entries
// (same "own records only" shape as ClientPolicy/ReferralPolicy),
// just never anyone else's and never able to create/edit one directly
// (system-created only, via CommissionService — see markPaid() for the
// one allowed mutation).
class CommissionLedgerPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // narrowed to "own only" at the query level for Agent — see CommissionLedgerController::index
    }

    public function view(User $user, CommissionLedger $commissionLedger): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->company_id !== $commissionLedger->company_id) {
            return false;
        }

        return $user->isCompanyAdmin() || $commissionLedger->agent_id === $user->id;
    }

    // No create()/update()/delete() — rows are only ever written by
    // CommissionService::recordForReferral() (system-triggered at
    // Complete Payment) and never edited or removed once created (BR-4
    // immutability). markPaid() below is the one deliberately narrow
    // exception, and only for the one mutable field (payment_status/paid_at).

    /**
     * Marking a commission as paid is a financial/administrative
     * action — deliberately NOT available to the Agent it belongs to
     * (an agent marking their own commission "paid" would be an
     * obvious self-dealing gap). Company Admin/Super Admin only.
     */
    public function markPaid(User $user, CommissionLedger $commissionLedger): bool
    {
        return $this->mayPayFor($user, $commissionLedger->company_id);
    }

    /**
     * 2026-09-15 — the same permission, asked about a PERSON instead of a row.
     *
     * POST /commission-ledger/mark-paid settles everything one agent is owed
     * in a single act, so there is no row to gate on when the request
     * arrives. Authorised with the payee's company rather than the rows',
     * which is the stricter of the two: the rows all belong to the payee's
     * company anyway, and asking about the person refuses the request before
     * anything is read.
     *
     * Deliberately delegating to the same predicate as markPaid() rather than
     * repeating the clause — this is the rule that keeps an Agent from
     * settling their own commission, and two copies of it is one copy that
     * can be loosened without the other noticing.
     *
     * Invoked as authorize('markAgentPaid', [CommissionLedger::class, $payee]).
     */
    public function markAgentPaid(User $user, User $payee): bool
    {
        return $this->mayPayFor($user, $payee->company_id);
    }

    private function mayPayFor(User $user, ?int $companyId): bool
    {
        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $companyId !== null && $user->company_id === $companyId);
    }
}
