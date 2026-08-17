<?php

namespace App\Http\Requests;

use App\Exceptions\LoginBlockedException;
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

        if (! Auth::guard('web')->attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

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

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
