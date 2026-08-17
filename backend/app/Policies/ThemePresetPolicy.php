<?php

namespace App\Policies;

use App\Models\ThemePreset;
use App\Models\User;
use App\Services\Theme\ThemePresetService;
use Illuminate\Auth\Access\Response;

/**
 * TASK-161 §3.2 — theme presets are ADMIN CONFIG, not a browsable
 * catalogue: a Company Admin manages their own company's, a Super Admin
 * any company's, and an **Agent has no access at all** (403 on every
 * route, including list). This is deliberately stricter than
 * BrandPolicy/ClientCategoryPolicy, where `viewAny` is open to the whole
 * company because agents need those lists to do their job — nothing in the
 * Agent Portal reads presets.
 *
 * The company check is belt-and-braces with TenantScope (§5 rules 2–3):
 * the scope keeps a Company Admin's queries inside their own company, this
 * blocks a Super-Admin-visible record reaching the wrong admin.
 */
class ThemePresetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function view(User $user, ThemePreset $themePreset): bool
    {
        return $user->isSuperAdmin()
            || ($user->isCompanyAdmin() && $user->company_id === $themePreset->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    /**
     * TASK-164 §1 — APPLYING a preset is always allowed, including for a
     * system one. That is the entire point of shipping designed palettes:
     * they exist to be used.
     *
     * Split out of `update` for exactly that reason. It used to reuse the
     * update check, so the moment `update` learned to refuse system presets
     * a Company Admin would have been unable to apply the very palettes
     * this task adds.
     */
    public function apply(User $user, ThemePreset $themePreset): bool
    {
        return $this->view($user, $themePreset);
    }

    /**
     * Renaming. TASK-164 §1: a system preset is read-only.
     *
     * The role/tenant check runs FIRST and returns a plain `false` (403 /
     * 404-behind-TenantScope). Only a caller who WOULD have been allowed
     * gets the 422 — otherwise the refusal would confirm the existence and
     * nature of a row belonging to a company that is none of their
     * business.
     *
     * denyWithStatus(422) rather than a bare deny: 403 would say "this is
     * not yours", which is wrong — it IS theirs, they may see it and apply
     * it, they simply may not change it. Same message and same status as
     * ThemePresetService::guardNotSystem(), which re-checks this for any
     * caller that never passes through a Gate at all.
     */
    public function update(User $user, ThemePreset $themePreset): Response|bool
    {
        if (! $this->view($user, $themePreset)) {
            return false;
        }

        return $themePreset->is_system
            ? Response::denyWithStatus(422, ThemePresetService::SYSTEM_PRESET_READ_ONLY_MESSAGE)
            : Response::allow();
    }

    /** Deleting — same rule and same reasoning as update(). */
    public function delete(User $user, ThemePreset $themePreset): Response|bool
    {
        return $this->update($user, $themePreset);
    }
}
