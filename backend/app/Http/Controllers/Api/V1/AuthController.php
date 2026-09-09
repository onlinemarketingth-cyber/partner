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
         * 2026-09-09 (human: "ทำให้อายุการ Login นานกว่านี้แบบ Facebook หรือ
         * YouTube ทำอย่างไร").
         *
         * It was a flat 12 hours with no renewal, so an agent using the app
         * all day was signed out in the middle of using it — and the
         * "จดจำฉัน" box on the login screen did nothing at all here, because
         * this app authenticates with a BEARER TOKEN and remember-me is a
         * cookie mechanism. A control that visibly does nothing is worse than
         * no control: the agent ticks it, is thrown out anyway, and concludes
         * the app is broken.
         *
         * Now the box decides the length, and the token SLIDES
         * (ExtendAccessToken), so the clock only runs while nobody is using
         * it. That is what "like Facebook" actually is — not a long fuse, but
         * one that resets every time you touch it.
         *
         * Sanctum's own default is no expiration at all, which stays the
         * wrong default for a system that moves money.
         */
        if ($isTokenMode) {
            $remember = $request->boolean('remember');
            /*
             * The window is recorded in the token's NAME, because the
             * sliding-renewal middleware has to extend by the window this
             * token was issued with and `expires_at` stops being able to
             * answer that the first time it is extended. A name is a column
             * Sanctum already has, so this needs no migration and no second
             * source of truth.
             */
            $expiresAt = now()->addMinutes(self::tokenLifetimeMinutes($remember));
            $token = $user->createToken(
                $remember ? self::TOKEN_NAME_REMEMBER : self::TOKEN_NAME,
                ['*'],
                $expiresAt,
            )->plainTextToken;

            return $resource->additional([
                'token' => $token,
                'token_expires_at' => $expiresAt->toIso8601String(),
            ]);
        }

        return $resource;
    }

    /**
     * How long a portal token survives WITHOUT BEING USED.
     *
     * The split exists because "จดจำฉัน" is a real answer to a real question:
     * an agent on their own phone means one thing, the same agent on a shared
     * or borrowed device means another. A single number for both would have to
     * be the short one to stay safe — which is the behaviour being complained
     * about.
     *
     * Public because the sliding-renewal middleware extends by the SAME window
     * the token was issued with. Two copies of this number would drift, and
     * the drift would surface as people being signed out early for no visible
     * reason.
     */
    /** The two token names, which are also how the renewal window is recorded. */
    public const TOKEN_NAME = 'agent-portal';

    public const TOKEN_NAME_REMEMBER = 'agent-portal-remember';

    public static function tokenLifetimeMinutes(bool $remember): int
    {
        // 30 days / 1 day — IDLE time, not total time (see ExtendAccessToken).
        return $remember ? 60 * 24 * 30 : 60 * 24;
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
