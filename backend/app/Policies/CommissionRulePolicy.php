<?php

namespace App\Policies;

use App\Models\CommissionRule;
use App\Models\User;

// BR-2 config is sensitive compensation data — unlike the rest of the
// catalog, Agents do not get read access here. They see their own
// earnings via CommissionLedger (a separate, already-scoped domain),
// never the raw rate table. Company Admin/Super Admin read it; only
// Super Admin writes it.
//
// 2026-09-11 (owner decision) — WRITES NARROWED TO SUPER ADMIN. A
// commission rate is money: the number here decides what the platform
// pays out, so one person owns it rather than every tenant admin. Reads
// stay exactly as they were, because a Company Admin still has to see
// the rates their agents are earning under in order to run the company.
// WHAT IT COSTS: a Company Admin can no longer set their own commission
// rate at all — not on their own products, and not on a shared catalog
// product they sell. Every rate change is now a request to the platform
// owner. Same shape and reasoning as CatalogBrandPolicy (ADR-036):
// reads open, writes Super-Admin-only.
class CommissionRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function view(User $user, CommissionRule $commissionRule): bool
    {
        return $user->isSuperAdmin()
            || ($user->isCompanyAdmin() && $user->company_id === $commissionRule->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, CommissionRule $commissionRule): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, CommissionRule $commissionRule): bool
    {
        return $user->isSuperAdmin();
    }
}
