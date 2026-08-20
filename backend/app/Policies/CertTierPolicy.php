<?php

namespace App\Policies;

use App\Models\CertTier;
use App\Models\User;

/**
 * TASK-221 — reading a cert tier is open to everyone; changing one is
 * Super Admin only.
 *
 * THE ASYMMETRY IS THE POINT. `cert_tiers` has no company_id (see the
 * table's migration): every company on the platform shares ONE list. A
 * Company Admin renaming "Basic" would rename it for every tenant, and
 * deleting a tier would reach into other companies' commission rules,
 * modules and certifications. So writes stop at the platform operator —
 * the same call made for shared theme presets (TASK-217) and the platform
 * SMTP settings (TASK-190).
 *
 * viewAny/view stay open to EVERY authenticated role, Agent included: the
 * Agent Portal renders Academy progress against these tiers, and the Admin
 * catalog's commission-rule form needs the list to build its <select>.
 * That was already true before this task and is not widened here.
 */
class CertTierPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CertTier $certTier): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, CertTier $certTier): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, CertTier $certTier): bool
    {
        return $user->isSuperAdmin();
    }
}
