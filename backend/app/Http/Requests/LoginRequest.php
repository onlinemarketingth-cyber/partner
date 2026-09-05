<?php

namespace App\Http\Requests;

use App\Exceptions\LoginBlockedException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\LoginGateService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Standard Laravel session-auth login request (same pattern Laravel's
// own Breeze starter kit uses) — rate-limit + lockout per CLAUDE.md
// Section 6 ("rate-limit login/OTP, lockout after repeated failures").
//
// TASK-115 / ADR-025 §8 adds the approval + verification gate immediately
// after the credential check below. It lives HERE rather than in
// AuthController for one reason: this is the method that calls
// Auth::attempt(), and a blocked login must undo that attempt's session
// write. Keeping the "log in" and the "undo the log in" adjacent means a
// future edit cannot move one without seeing the other.
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            // Bug fix (2026-08-02) — LoginView's "จดจำฉัน" checkbox now
            // actually reaches here (see stores/auth.ts's login()); was
            // already read below via $this->boolean('remember') but never
            // declared as a real input. Optional: $this->boolean() already
            // defaults falsy values safely either way.
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException      wrong password / unknown email / locked out
     * @throws \App\Exceptions\LoginBlockedException           correct password, but unverified/pending/rejected
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        /*
         * 2026-08-27 — TOKEN MODE (agent portal, multi-domain).
         *
         * The agent portal authenticates with a Bearer personal access
         * token, so its requests are deliberately NOT on a Sanctum
         * stateful domain any more: no session middleware runs for them,
         * and `Auth::guard('web')->attempt()` below would fatal on the
         * session write it performs. `validate()` is attempt() minus that
         * write — same credential check, same provider, same hashing —
         * so the branch below shares every rule this one does and only
         * skips the part that needs a session that does not exist.
         *
         * The admin console still logs in the original way (its own
         * domain remains stateful, it never sends this header), so the
         * whole block below is untouched for it.
         */
        if ($this->header('X-Auth-Mode') === 'token') {
            $this->authenticateStateless();

            return;
        }

        if (! Auth::guard('web')->attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            $this->recordFailedAttempt();

            // UNCHANGED, AND DELIBERATELY SO. This single branch answers both
            // "no such user" and "wrong password" with the same 422 and the
            // same throttle hit, which is what makes the gate below
            // non-enumerable — see LoginGateService's analysis. Do not split
            // it into two messages "to be more helpful".
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // Cleared BEFORE the gate, not after: the throttle key exists to stop
        // password GUESSING, and a correct password ends the guessing whether
        // or not the account is then allowed in. Clearing here also means a
        // pending/unverified user who retries a few times while waiting can
        // never lock themselves out of the resend-verification affordance.
        RateLimiter::clear($this->throttleKey());

        // TASK-115 / ADR-025 §8. Runs after attempt() (so we know the caller
        // owns the account) and before AuthController regenerates the
        // session. Throws LoginBlockedException -> 403 with a distinguishable
        // error_code.
        /** @var \App\Models\User $user */
        $user = Auth::guard('web')->user();

        try {
            app(LoginGateService::class)->assertMayLogIn($user);
        } catch (LoginBlockedException $e) {
            $this->recordBlockedLogin($user, $e);

            // attempt() has already written the user into the session (and,
            // with `remember`, minted a remember cookie). Undo both before the
            // 403 leaves, or a blocked account would hold a usable session
            // cookie and every subsequent auth:sanctum request would succeed —
            // the gate would block the login screen and nothing else.
            // logout() removes the session key AND clears/forgets the recaller,
            // which is exactly the two things attempt() just did.
            Auth::guard('web')->logout();

            throw $e;
        }
    }

    /**
     * Credential check + account gate WITHOUT touching the session.
     *
     * Deliberately mirrors authenticate()'s session path line for line —
     * same throttle hit on failure, same single non-enumerable 422, same
     * RateLimiter::clear() placement, same LoginGateService call. The two
     * differences are both forced by the absence of a session:
     *
     *   1. validate() instead of attempt() — checks the password against
     *      the same user provider but writes nothing.
     *   2. setUser() instead of the gate's logout() undo — nothing was
     *      persisted to undo, so a blocked account simply never gets the
     *      user set, and no token is ever minted for it upstream.
     *
     * `remember` is meaningless here and ignored on purpose: token
     * lifetime is the token's own expiry, not a remember-me cookie.
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \App\Exceptions\LoginBlockedException
     */
    protected function authenticateStateless(): void
    {
        $guard = Auth::guard('web');

        if (! $guard->validate($this->only('email', 'password'))) {
            RateLimiter::hit($this->throttleKey());
            $this->recordFailedAttempt();

            // Same single branch, same reason as the session path above:
            // "no such user" and "wrong password" must stay
            // indistinguishable. Do not split this into two messages.
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        /** @var \App\Models\User $user */
        $user = $guard->getLastAttempted();

        // Throws LoginBlockedException -> 403 before the user is ever set
        // on the guard, so a blocked account cannot reach the token-minting
        // code in AuthController::login() at all.
        try {
            app(LoginGateService::class)->assertMayLogIn($user);
        } catch (LoginBlockedException $e) {
            $this->recordBlockedLogin($user, $e);

            throw $e;
        }

        // In-memory only (no session write): gives AuthController's
        // $request->user() the authenticated user for this one request.
        $guard->setUser($user);
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /*
     * ══ TASK-240 — THE FAILED HALF OF THE LOGIN TRAIL ══
     *
     * Successful logins have been audited since the 2026-08-21 security work
     * (`auth.login`, written in AuthController). Failures were not recorded
     * anywhere, so the first question anyone asks of a login trail — "has
     * somebody been trying to get into this account?" — had no answer at all.
     *
     * Three rules hold these methods together. Each exists because the
     * obvious implementation of a login log is harmful:
     *
     * 1. NO CREDENTIALS, EVER. Not the attempted password, obviously — but
     *    also not the attempted EMAIL when it belongs to no account. A log of
     *    addresses that people tried is a list of addresses somebody probes
     *    to build, readable by everyone who can read the audit screen. The
     *    row still exists (the attempt is the fact worth keeping); it just
     *    identifies nobody.
     *
     * 2. AT MOST SIX ROWS PER LOCKOUT. A bot pointed at one address would
     *    otherwise write a row per guess, forever, and drown the trail it is
     *    supposed to appear in. Five failures fit under the throttle, then
     *    RecordAuthLockout writes exactly one `auth.lockout`, and every
     *    attempt after that is refused by ensureIsNotRateLimited() before
     *    reaching this class's audit code at all.
     *
     * 3. A REFUSED GATE IS NOT A FAILED PASSWORD. Someone whose password was
     *    correct but whose account is deactivated, unverified or awaiting
     *    approval is a different event with a different follow-up, and it
     *    gets its own action plus the reason. Collapsing the two would make
     *    the trail unable to tell an attack from a person waiting to be
     *    approved.
     */

    /**
     * A wrong password, or an address with no account behind it.
     */
    private function recordFailedAttempt(): void
    {
        $email = Str::lower(trim((string) $this->string('email')));
        $user = User::withoutGlobalScopes()->where('email', $email)->first();

        /*
         * ONLY THE FAILURE. `auth.lockout` belongs to RecordAuthLockout,
         * which has owned it since the 2026-08-21 security audit and listens
         * for the Lockout event this class already fires. Writing it here too
         * would put two different authors on one action name and double every
         * lockout in the trail — which is exactly the bug this change found
         * in the listener's own registration.
         */
        $this->writeAuthAudit(
            'auth.login_failed',
            $user,
            // Rule 1: `known` says whether the address exists WITHOUT
            // repeating it. That single boolean is what makes a run of
            // failures against one real account visible.
            ['known_account' => $user !== null],
        );
    }

    /**
     * The password was right; the account is not allowed in.
     */
    private function recordBlockedLogin(User $user, LoginBlockedException $exception): void
    {
        $this->writeAuthAudit('auth.login_blocked', $user, [
            // LoginBlockReason distinguishes deactivated / unverified /
            // pending / rejected / company inactive — the whole point of
            // recording this separately.
            'reason' => $exception->reason->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function writeAuthAudit(string $action, ?User $user, array $newValues): void
    {
        AuditLog::create([
            'company_id' => $user?->company_id,
            // Null for an unknown address — there is no actor to name, and
            // this row exists to say that an attempt happened at all.
            'actor_user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => User::class,
            // 0 when nobody matched: the polymorphic column is not nullable,
            // and a real id here would attribute a stranger's attempt to a
            // real person's record.
            'auditable_id' => $user?->id ?? 0,
            'old_values' => null,
            'new_values' => $newValues,
            // The one field that matters most on these rows.
            'ip_address' => $this->ip(),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
