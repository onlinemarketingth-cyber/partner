<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TASK-247 — "change my own login address".
 *
 * Self-scoped by construction like every other /me endpoint: the controller
 * only ever passes $request->user(), never a route-bound {user}, so there is
 * no IDOR surface and no Policy to consult.
 *
 * `current_password` is REQUIRED, and that is the whole security design of
 * this endpoint. The email is the identifier the account signs in with, so
 * changing it is the first half of an account takeover — a stolen session
 * cookie alone must not be able to point the account at an address the
 * attacker controls. This is the same rule UpdatePasswordRequest applies for
 * the same reason (Section 6: Authentication baseline); the two together mean
 * neither credential can be replaced by a session alone.
 *
 * Uniqueness IGNORES this user, so re-submitting the address they already
 * have is a harmless no-op rather than a confusing "email already taken" on
 * their own address. The Service treats an unchanged address as nothing
 * happening and writes no audit row for it.
 */
class UpdateEmailRequest extends FormRequest
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
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()?->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'ต้องกรอกรหัสผ่านปัจจุบันเพื่อยืนยันตัวตน',
            'current_password.current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง',
            'email.required' => 'ต้องกรอกอีเมล',
            'email.email' => 'รูปแบบอีเมลไม่ถูกต้อง',
            'email.unique' => 'อีเมลนี้ถูกใช้กับบัญชีอื่นแล้ว',
        ];
    }
}
