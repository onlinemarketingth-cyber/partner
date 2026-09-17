<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2026-09-10 (human: "ที่ได้สิทธิ์ในการตัดได้เฉพาะหน้าการตัดสิทธิ์ เพราะทำงาน
 * คนละหน้าที่กัน") · generalised 2026-09-16 for Company Partner.
 *
 * Roles that reach a few named endpoints and nothing else.
 *
 * ── WHY A WALL AND NOT A HUNDRED POLICY EDITS ──
 *
 * (Kept verbatim from RestrictVoucherStaff, which this replaces, because the
 * reasoning is what makes the shape correct and it is easy to lose.)
 *
 * The obvious way to build these roles is to check every Policy and Controller
 * gate in turn. That was tried first and it is the wrong shape: most admin
 * gates ask `isCompanyAdmin()` and correctly refuse a new role for free — but
 * `OrderPolicy::viewAny()` returns true for any authenticated user (narrowed
 * at the query level instead), and so do others. Whether the next one of those
 * is a leak depends on remembering these roles exist while writing it, which
 * nobody will in six months.
 *
 * So the boundary is an ALLOWLIST at the door: these roles may reach the paths
 * named below and receive 403 everywhere else. A new endpoint is denied to
 * them by default, which is the only default that stays correct without
 * anybody maintaining it (§6 fail-closed).
 *
 * ── WHY ONE MIDDLEWARE AND NOT TWO ──
 *
 * 2026-09-16: Company Partner needs the same treatment, and the tempting move
 * is a second class next to the first. Two walls doing one job drift — the day
 * somebody adds `/me` to one list and not the other, one role can render the
 * console and the other cannot, for no reason anybody wrote down. One table,
 * keyed by role, keeps the decision in a single diff.
 *
 * ── WHAT IS ON EACH LIST, AND WHY ──
 *
 * Shared by both, and the reason is the same for each:
 *   /me         every app in this system asks it to know who is logged in;
 *               without it the console cannot render at all.
 *   /logout     a person who can log in must be able to log out.
 *   /profile    their own name, password and language. Their own record is
 *               not somebody else's data.
 *   /public/*   unauthenticated surfaces that happen to carry a session.
 *
 * Voucher staff additionally get /vouchers — the job itself.
 *
 * A supplier gets /vouchers too (they provide the service being redeemed) plus
 * /supplier/*, which is where every screen built for them lives. Nothing else:
 * not /orders, not /clients, not /commission-*. Their own orders are served by
 * /supplier/orders, filtered by supplier_id, precisely so that
 * reaching them cannot be done through an endpoint that was written for a
 * tenant and scoped like one.
 *
 * 2026-09-17: note the SINGULAR. `supplier` is opened here; `suppliers` (the
 * Super-Admin screen that edits every supplier's deal terms and bank details)
 * and `supplier-payouts` (the one that decides what gets paid) are not — the
 * matcher below compares whole segments precisely so that a one-letter
 * difference cannot become an authorisation difference by accident.
 */
class RestrictScopedRole
{
    /** Paths every scoped role needs just to be a logged-in user. */
    private const BASELINE = [
        'me',
        'logout',
        'profile',
        'public',
    ];

    /**
     * Role value => the path prefixes it may reach, ON TOP OF BASELINE.
     *
     * Relative to the API root, WITHOUT a leading slash. A role that is not a
     * key here is not restricted by this middleware at all.
     *
     * @var array<string, list<string>>
     */
    private const EXTRA_PREFIXES = [
        UserRole::VoucherStaff->value => [
            'vouchers',
        ],
        UserRole::CompanyPartner->value => [
            'vouchers',
            'supplier',
        ],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->user()?->role?->value;

        if ($role === null || ! array_key_exists($role, self::EXTRA_PREFIXES)) {
            return $next($request);
        }

        if ($this->isAllowed($request, $role)) {
            return $next($request);
        }

        abort(403, $this->refusalFor($role));
    }

    private function isAllowed(Request $request, string $role): bool
    {
        // Anything outside the versioned API (Sanctum's CSRF cookie, health
        // checks, the SPA itself) is not what these roles are being kept out
        // of.
        $path = $request->path();

        if (! str_starts_with($path, 'api/v1/')) {
            return true;
        }

        $relative = substr($path, strlen('api/v1/'));

        foreach ([...self::BASELINE, ...self::EXTRA_PREFIXES[$role]] as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Names the screen the account DOES work on.
     *
     * "403" on its own sends somebody to support. Saying which app this login
     * is for turns the same refusal into an answer.
     */
    private function refusalFor(string $role): string
    {
        return match ($role) {
            UserRole::VoucherStaff->value => 'บัญชีนี้ใช้ได้เฉพาะหน้าตัดสิทธิ์บัตรกำนัลเท่านั้น',
            UserRole::CompanyPartner->value => 'บัญชีนี้ใช้ได้เฉพาะหน้าสำหรับบริษัทคู่ค้าเท่านั้น',
            default => 'บัญชีนี้ไม่มีสิทธิ์เข้าถึงส่วนนี้',
        };
    }
}
