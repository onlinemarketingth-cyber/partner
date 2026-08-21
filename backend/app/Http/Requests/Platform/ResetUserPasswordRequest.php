<?php

namespace App\Http\Requests\Platform;

use App\Support\PasswordRuleMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

// Companion action to StoreUserRequest's "admin types a temp password"
// flow — since there's no email/invite system, this is also how an
// Admin gets a locked-out agent back in: set a new temporary password
// directly, communicate it out of band. Uses the same authorization
// shape as UpdateUserRequest (UserPolicy::update()).
class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // SECURITY AUDIT 2026-08-21 (V18) — one policy, registered in
            // AppServiceProvider. An admin-set password is the one an agent
            // logs in with, so it gets the same floor as a self-chosen one.
            'password' => ['required', 'string', Password::defaults()],
        ];
    }

    /**
     * Thai text for the shared password policy — see PasswordRuleMessages.
     * Without this the Password rule answers in English inside a Thai app.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return PasswordRuleMessages::all();
    }
}
