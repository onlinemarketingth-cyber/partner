<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2026-09-10 (human: "ที่ได้สิทธิ์ในการตัดได้เฉพาะหน้าการตัดสิทธิ์
 * เพราะทำงานคนละหน้าที่กัน").
 *
 * Front-desk staff reach the redemption endpoints and nothing else.
 *
 * ── WHY A WALL AND NOT A HUNDRED POLICY EDITS ──
 *
 * The obvious way to build this role is to check every Policy and Controller
 * gate in turn. That was tried first and it is the wrong shape: most admin
 * gates ask `isCompanyAdmin()` and correctly refuse a new role for free —
 * but `OrderPolicy::viewAny()` returns true for any authenticated user
 * (narrowed at the query level instead), and so do others. Whether the next
 * one of those is a leak depends on remembering this role exists while
 * writing it, which nobody will in six months.
 *
 * So the boundary is an ALLOWLIST at the door: this role may reach the paths
 * named below and receives 403 everywhere else. A new endpoint is denied to
 * them by default, which is the only default that stays correct without
 * anybody maintaining it (§6 fail-closed).
 *
 * ── WHAT IS ON THE LIST, AND WHY EACH ONE ──
 *
 *   /vouchers/*     the job itself.
 *   /me             every app in this system asks it to know who is logged
 *                   in; without it the console cannot render at all.
 *   /logout         a person who can log in must be able to log out.
 *   /profile        their own name, password and language. Their own record
 *                   is not somebody else's data.
 *   /public/*       unauthenticated surfaces that happen to carry a session.
 *
 * Everything else — orders, clients, commission, academy, settings, other
 * users — is refused, and refused by this middleware rather than by luck.
 */
class RestrictVoucherStaff
{
    /**
     * Path prefixes, relative to the API root and WITHOUT a leading slash.
     * Matched against `$request->path()` minus the `api/v1/` prefix.
     *
     * @var list<string>
     */
    private const ALLOWED_PREFIXES = [
        'vouchers',
        'me',
        'logout',
        'profile',
        'public',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->role !== UserRole::VoucherStaff) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        abort(403, 'บัญชีนี้ใช้ได้เฉพาะหน้าตัดสิทธิ์บัตรกำนัลเท่านั้น');
    }

    private function isAllowed(Request $request): bool
    {
        // Anything outside the versioned API (Sanctum's CSRF cookie, health
        // checks, the SPA itself) is not what this role is being kept out of.
        $path = $request->path();

        if (! str_starts_with($path, 'api/v1/')) {
            return true;
        }

        $relative = substr($path, strlen('api/v1/'));

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
