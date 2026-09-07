<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TASK-247 — a refused login that says how much room is left.
 *
 * ── THE PROBLEM ──
 *
 * The login screen could tell somebody their password was wrong, and it could
 * tell them they had been locked out. It could not tell them they were ABOUT
 * to be locked out, and when the lockout came it dropped the one fact that
 * makes it survivable: how long. "พยายามเข้าสู่ระบบบ่อยเกินไป กรุณาลองใหม่ใน
 * ภายหลัง" leaves a person refreshing a page for an unknown number of minutes,
 * and the usual next step is to ask an admin to reset a password that was
 * never wrong.
 *
 * ── WHY A CUSTOM EXCEPTION AND NOT ValidationException ──
 *
 * The numbers have to reach the client as NUMBERS. This login screen owns its
 * own copy in Thai and English (the API runs under APP_LOCALE=en, and the
 * screen has a TH/EN switch), so a sentence composed here would arrive in one
 * language and be rendered in the other. ValidationException's body has
 * nowhere to put a count.
 *
 * The response therefore keeps ValidationException's exact 422 shape —
 * `message` plus `errors.email` — so every existing caller, test and
 * `assertJsonValidationErrors('email')` keeps working unchanged, and ADDS two
 * keys. Additive, on purpose: the agent portal reads the old shape.
 *
 * ── WHAT IT DOES NOT SAY ──
 *
 * Not whether the address exists. `attempts_remaining` counts the throttle
 * key, which is email+IP and is incremented identically for a wrong password
 * and for an address with no account behind it — so the number is the same in
 * both cases and reveals nothing the caller could not count themselves. The
 * non-enumerable single branch in LoginRequest is untouched: same status, same
 * message, same throttle hit.
 *
 * RESPONSE SHAPE (both keys always present, so the client binds without
 * null-guarding each branch):
 *
 *   422 {
 *     "message":            "<the same auth.failed / auth.throttle line>",
 *     "errors":             { "email": [ "<same line>" ] },
 *     "attempts_remaining": int|null,   // null once locked out
 *     "lockout_seconds":    int|null    // null until locked out
 *   }
 */
class LoginFailedException extends Exception
{
    private function __construct(
        string $message,
        public readonly ?int $attemptsRemaining,
        public readonly ?int $lockoutSeconds,
    ) {
        parent::__construct($message);
    }

    /**
     * A wrong password, or an address with no account behind it — the two are
     * deliberately indistinguishable here and must stay that way.
     */
    public static function credentials(string $message, int $attemptsRemaining): self
    {
        return new self($message, max(0, $attemptsRemaining), null);
    }

    /** The throttle is closed; $seconds is how long until it opens. */
    public static function throttled(string $message, int $seconds): self
    {
        return new self($message, null, max(0, $seconds));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            // ValidationException's shape, kept exactly: the field this
            // belongs to has always been `email`, and clients bind to it.
            'errors' => ['email' => [$this->getMessage()]],
            'attempts_remaining' => $this->attemptsRemaining,
            'lockout_seconds' => $this->lockoutSeconds,
        ], 422);
    }
}
