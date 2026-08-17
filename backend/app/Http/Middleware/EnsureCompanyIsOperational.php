<?php

namespace App\Http\Middleware;

use App\Enums\LoginBlockReason;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-183 §3.3 — a deactivated or soft-deleted company must stop working on
 * EVERY authenticated request, not only at the next login.
 *
 * WHY THIS EXISTS AT ALL (and why the login gate is not enough). A Sanctum
 * session cookie or personal access token minted BEFORE the deactivation never
 * passes through LoginGateService again. With login-only enforcement, an Admin
 * flips the switch, watches it turn off, and every user who happens to already
 * be logged in carries on selling, submitting referrals and having commission
 * written for them — for as long as their session lives, which for an active
 * daily user is effectively forever. "Deactivate" would then take effect at a
 * moment nobody can name. That is the defect this class closes, and it is the
 * assertion most likely to be missing from a naive implementation, so it is
 * pinned first in CompanyDeactivationTest.
 *
 * WHY MIDDLEWARE RATHER THAN A POLICY OR A GLOBAL SCOPE:
 *   * A Policy is per-model and per-ability. There are ~60 Policy methods in
 *     this app and the guarantee needed here is "no authenticated action at
 *     all", which would mean editing every one of them and remembering to edit
 *     the next one too.
 *   * TenantScope cannot express it either: it FILTERS rows by company_id, it
 *     does not refuse a request, and it returns early with no filter at all
 *     when there is no authenticated user (TenantScope.php:61-63) — which is
 *     also why the public endpoints (§3.5) each carry their own check rather
 *     than relying on a scope.
 * A single middleware on the authenticated route group is the only place where
 * "every authenticated request" is spelled once and cannot be forgotten by the
 * next endpoint someone adds.
 *
 * FAIL CLOSED. It asks User::belongsToOperationalCompany(), which resolves the
 * tenant withTrashed() and answers through Company::isOperational() — the one
 * predicate (§3.1). A company_id that resolves to nothing is refused, not
 * waved through.
 *
 * SUPER ADMIN (company_id = null) IS NEVER REFUSED — see
 * User::belongsToOperationalCompany()'s docblock. Deliberate and load-bearing:
 * the Super Admin is who reactivates a company, so gating them would make a
 * deactivation irreversible through the API.
 *
 * RESPONSE (§3.4): 403 with `error_code: company_inactive` and the Thai copy
 * from LoginBlockReason::CompanyInactive — the SAME code and the SAME sentence
 * the login gate emits, so the SPA needs one branch, not two, and so a user
 * refused mid-session and a user refused at the login screen are told the same
 * thing. See this task's completion report for the full status-code reasoning
 * (in short: 403 not 401, because the caller IS authenticated and a 401 would
 * make the SPA say "session expired" — an instruction that would send them to
 * re-login instead of to their company; and 403 not 422, because nothing about
 * their input is wrong).
 */
class EnsureCompanyIsOperational
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // No authenticated user: not this middleware's business. It is always
        // registered behind auth:sanctum, so this is unreachable in practice —
        // kept so that mis-registering it in front of the auth middleware
        // would produce a plain 401 from auth, not a 500 from here.
        if ($user === null) {
            return $next($request);
        }

        if (! $user->belongsToOperationalCompany()) {
            return new JsonResponse([
                'message' => LoginBlockReason::CompanyInactive->message(),
                'error_code' => LoginBlockReason::CompanyInactive->value,
            ], 403);
        }

        return $next($request);
    }
}
