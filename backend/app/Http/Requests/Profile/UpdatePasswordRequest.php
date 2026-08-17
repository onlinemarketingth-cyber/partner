<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

// Self-service "change my own password" — always operates on
// $request->user() (see UserProfileController), same self-scoped pattern
// as the rest of the profile endpoints. `current_password` is Laravel's
// built-in rule (Illuminate\Validation\Rules\Password's sibling) — it
// re-checks the given value against the authenticated user's hashed
// password via Hash::check() under the hood, so a stolen session cookie
// alone can't be used to silently take over an account by changing its
// password (Section 6: Authentication baseline).
class UpdatePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง',
            'password.different' => 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม',
        ];
    }
}
