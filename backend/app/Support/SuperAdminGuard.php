<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;

/**
 * 2026-09-18 (human: "เพิ่มสิทธิ์ Super Admin ในการเพิ่มและแก้ไข user").
 *
 * ── WHY THIS CLASS EXISTS ──
 *
 * Super Admin became creatable and editable through the API on this date,
 * reversing a deliberate decision recorded in UserPolicy's own docblock
 * ("too sensitive for a same-tier 'add teammate' flow"). The owner asked for
 * it with guards attached, and this is the one of them that needs a QUERY
 * rather than a rule: THE LAST ACTIVE SUPER ADMIN MAY NOT BE REMOVED.
 *
 * Before this, the only path to the role was `artisan admin:create-super`,
 * which cannot run on a request — so "demote the last one" was not a state
 * the system could reach. It is now, and its cost is total: a platform with
 * no Super Admin has nobody who can create companies, edit suppliers,
 * approve payouts or mint another Super Admin, and the only way back is SSH
 * to the production host. That is a recoverable-but-terrible outcome for a
 * click, and the owner chose to make it unreachable instead.
 *
 * ── WHY ONE CLASS RATHER THAN A METHOD ON EITHER CALLER ──
 *
 * Two different paths can remove the last one — a role change
 * (UpdateUserRequest) and a deactivation (UserPolicy::delete) — and they
 * answer to different layers. A count that lived on one of them would be
 * re-implemented, slightly differently, on the other; two definitions of
 * "the last Super Admin" is how a guard ends up holding on one path only.
 */
final class SuperAdminGuard
{
    /** The one sentence both callers show, so they cannot word it differently. */
    public const LAST_ONE_MESSAGE = 'นี่คือ Super Admin คนสุดท้ายที่ใช้งานอยู่ — ตั้ง Super Admin อีกคนก่อน จึงจะถอดสิทธิ์หรือปิดบัญชีรายนี้ได้';

    /**
     * Super Admins who can still log in right now.
     *
     * `withoutGlobalScopes()` for a reason that would otherwise make this
     * silently useless: a Super Admin has NO company_id, and TenantScope is
     * fail-closed since the supplier rework — a count run inside any
     * tenant-scoped context would answer zero, and a guard that reads "there
     * are no Super Admins left" is a guard that permits everything.
     *
     * Dropping the global scopes also drops SoftDeletingScope, so the
     * deleted_at filter is spelled out: a deactivated Super Admin cannot log
     * in, and must not be counted as cover for removing the one who can.
     */
    public static function activeCount(): int
    {
        return User::query()
            ->withoutGlobalScopes()
            ->where('role', UserRole::SuperAdmin->value)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Is this the only Super Admin still able to log in?
     *
     * False for anyone who is not an active Super Admin, so a caller may ask
     * about any user without checking the role first.
     */
    public static function isLastActive(User $target): bool
    {
        if (! $target->isSuperAdmin() || $target->deleted_at !== null) {
            return false;
        }

        return self::activeCount() <= 1;
    }
}
