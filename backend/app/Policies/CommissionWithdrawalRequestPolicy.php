<?php

namespace App\Policies;

use App\Models\CommissionWithdrawalRequest;
use App\Models\User;

/**
 * Who may see and act on a commission withdrawal request (2026-08-27).
 *
 * Deciding on one is the same authority as settling a commission row by
 * hand — Company Admin within their own company, or Super Admin (human
 * decision, 2026-08-27, matching CommissionLedgerPolicy::markPaid so the
 * system has ONE answer to "who may release money", not two that can drift).
 *
 * An agent may see and cancel their OWN request and nothing else. There is
 * no "team leader can see their downline's payouts" rule here on purpose:
 * what somebody earns and when they ask for it is theirs, and ADR-025's
 * team-visibility rules are about pipeline work, not personal finances.
 */
class CommissionWithdrawalRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // The QUERY is scoped per role — see the controllers.
    }

    public function view(User $user, CommissionWithdrawalRequest $request): bool
    {
        return $this->isOwnRequest($user, $request) || $this->mayDecide($user, $request);
    }

    /** Only the agent themselves, and only while nobody has decided (checked in the Service). */
    public function cancel(User $user, CommissionWithdrawalRequest $request): bool
    {
        return $this->isOwnRequest($user, $request);
    }

    public function decide(User $user, CommissionWithdrawalRequest $request): bool
    {
        return $this->mayDecide($user, $request);
    }

    /**
     * 2026-09-15 — may this user RAISE a payout for that agent?
     *
     * The same authority as deciding on one, asked about a person instead of
     * a row, because when the request arrives there is no row yet. Raising a
     * company payout creates something already Approved — the press IS the
     * decision (see WithdrawalSource) — so anything less than the decide
     * permission here would be a way around it.
     *
     * Invoked as authorize('raise', [CommissionWithdrawalRequest::class, $agent]).
     */
    public function raise(User $user, User $agent): bool
    {
        return $user->isSuperAdmin()
            || ($user->isCompanyAdmin() && $agent->company_id !== null && $user->company_id === $agent->company_id);
    }

    private function isOwnRequest(User $user, CommissionWithdrawalRequest $request): bool
    {
        return $user->id === $request->agent_id;
    }

    private function mayDecide(User $user, CommissionWithdrawalRequest $request): bool
    {
        return $user->isSuperAdmin()
            || ($user->isCompanyAdmin() && $user->company_id === $request->company_id);
    }
}
