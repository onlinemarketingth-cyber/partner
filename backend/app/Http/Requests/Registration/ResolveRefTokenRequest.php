<?php

namespace App\Http\Requests\Registration;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-114 / ADR-025 §5 — public, unauthenticated, the recruit-link twin
 * of ResolveInviteCodeRequest. Deliberately just as thin: shape only, no
 * business rule. Whether the token is USABLE is decided by
 * RegistrationService::resolveRefToken() and surfaces as the Controller's
 * generic 404, so this Request can never become a second, drifting copy
 * of that rule.
 *
 * `max:255` rather than `size:64` on purpose. The real tokens are exactly
 * 64 chars (Str::random(64)), but rejecting a 63-char string with a
 * DIFFERENT error than an unknown 64-char one hands an anonymous prober a
 * free oracle for the token format. One generic outcome for every bad
 * input.
 */
class ResolveRefTokenRequest extends FormRequest
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
            'ref_token' => ['required', 'string', 'max:255'],
        ];
    }
}
