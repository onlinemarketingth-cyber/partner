<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

// Section 7: Controller stays thin — validation lives in LoginRequest,
// there is no business logic here to push down into a Service.
class AuthController extends Controller
{
    public function login(LoginRequest $request): UserResource
    {
        $request->authenticate();

        /*
         * 2026-08-27 — token mode has no session to regenerate. The agent
         * portal is no longer on a Sanctum stateful domain, so the session
         * middleware never ran for this request and $request->session()
         * would throw. The admin console still takes the branch below,
         * unchanged: session fixation protection is exactly as it was for
         * every cookie-based login.
         */
        $isTokenMode = $request->header('X-Auth-Mode') === 'token';

        if (! $isTokenMode) {
            $request->session()->regenerate();
        }

        /** @var User $user */
        $user = $request->user();

        /*
         * SECURITY AUDIT 2026-08-21 (V19) — record that this login happened.
         *
         * Nothing recorded logins before. In a system that pays commission,
         * "which admin was signed in when this payout was approved, and
         * from where" had no answer at all — and the absence only becomes
         * visible at the exact moment somebody needs it, which is always
         * after the fact and never in time.
         *
         * Successes only. A failed attempt is already covered by the
         * throttle and by RecordAuthLockout when it becomes a pattern;
         * writing a row per wrong password would let anyone with a login
         * form fill this table on demand.
         *
         * No user agent: it is attacker-controlled free text, it is bulky,
         * and it answers no question the IP does not answer better.
         */
        AuditLog::create([
            'company_id' => $user->company_id,
            'actor_user_id' => $user->id,
            'action' => 'auth.login',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => null,
            'new_values' => ['role' => $user->role?->value],
            'ip_address' => $request->ip(),
        ]);

        // TASK-044 Phase A — this is the authenticated user's own row
        // (never a route-bound {user}), so the full bank_account_number
        // is safe to reveal here per the task spec's masking exception.
        $resource = UserResource::forOwner($user->load('company'));

        /*
         * 2026-08-27 — additive token issuance for the agent portal, which
         * must run on more than one first-party domain
         * (apps.liveto100club.com, a Parked Domain alias) and therefore
         * cannot rely on a host-only session cookie.
         *
         * The admin console never sends this header and keeps the exact
         * cookie-session behaviour above — nothing here can regress it.
         *
         * 12h expiry, set explicitly: Sanctum's own default is no
         * expiration at all, and a token that never dies is the wrong
         * default for a system that moves money.
         */
        if ($isTokenMode) {
            $expiresAt = now()->addHours(12);
            $token = $user->createToken('agent-portal', ['*'], $expiresAt)->plainTextToken;

            return $resource->additional([
                'token' => $token,
                'token_expires_at' => $expiresAt->toIso8601String(),
            ]);
        }

        return $resource;
    }

    public function logout(Request $request): Response
    {
        /*
         * 2026-08-27 — revoke the real API token when the caller
         * authenticated with one. A cookie-session request's
         * currentAccessToken() is Sanctum's TransientToken, NOT a
         * PersonalAccessToken, so this check skips it and the session
         * teardown below runs exactly as it always has for the admin
         * console.
         */
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();

            // No session exists on a token request (non-stateful domain),
            // so there is nothing to invalidate — and calling into it
            // would throw. Revoking the token IS the logout here.
            return response()->noContent();
        }

        auth('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): UserResource|Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->noContent(204);
        }

        // TASK-044 Phase A — GET /me is THE canonical "owning agent's own
        // profile view" the task spec calls out as the masking exception.
        return UserResource::forOwner($user->load('company'));
    }
}
