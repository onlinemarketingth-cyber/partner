<?php

namespace App\Http\Requests\Registration;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-115 (TASK-021 item 3) — input for the one actionable login-blocked
 * state.
 *
 * PUBLIC and UNAUTHENTICATED by necessity: the person asking cannot log in
 * (that is the whole point), so there is no session to identify them by.
 * That makes this endpoint an enumeration surface by construction, and the
 * defences are split across three layers:
 *   1. `throttle:5,1` on the route (Section 6 — every public endpoint);
 *   2. RegisterController::resendVerificationEmail() returns the SAME 200 and
 *      the SAME message whatever happens, so the response is not an oracle;
 *   3. RegistrationService::resendVerificationEmail() decides silently
 *      whether an email is actually warranted.
 *
 * There is deliberately NO `exists:users,email` rule here — that would turn
 * the validator itself into the oracle the other three layers are closing.
 */
class ResendVerificationEmailRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
