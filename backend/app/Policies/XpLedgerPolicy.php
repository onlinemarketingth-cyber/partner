<?php

namespace App\Policies;

use App\Models\User;
use App\Models\XpLedger;

// BR-5 — same "own earnings" visibility shape as CommissionLedgerPolicy,
// but even more restrictive: there is no mutable field at all here (no
// markPaid()-equivalent). Rows are exclusively written by
// GamificationService::awardXp(), never via the API.
class XpLedgerPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // narrowed to "own only" at the query level for Agent — see XpLedgerController::index
    }

    public function view(User $user, XpLedger $xpLedger): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->company_id !== $xpLedger->company_id) {
            return false;
        }

        return $user->isCompanyAdmin() || $xpLedger->user_id === $user->id;
    }

    // No create()/update()/delete() — fully system-written, append-only
    // (BR-4-style immutability, applied here even though XpLedger isn't
    // money). Never exposed as a mutable resource via the API.
}
