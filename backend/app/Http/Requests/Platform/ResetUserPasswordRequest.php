<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

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
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
