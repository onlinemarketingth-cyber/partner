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
        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $user->company_id === $commissionLedger->company_id);
    }
}
