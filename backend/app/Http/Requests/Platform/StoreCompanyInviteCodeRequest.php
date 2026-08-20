<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TASK-233 — creating a company signup link.
 *
 * `expires_at` and `max_uses` are BOTH `present` rather than `sometimes`.
 * They may each be null, and null means "forever" / "unlimited" — but the
 * caller has to send the key. BR-7: how long a company's recruitment link
 * lives and how many people may use it are business decisions, and the
 * point of `present` is that somebody has to make them. `sometimes` would
 * let an omitted field silently become "forever", which is the largest of
 * the available answers and the one nobody chose.
 */
class StoreCompanyInviteCodeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only a Super Admin names a company; a Company Admin's is taken
            // from their session and anything they send here is ignored by
            // the service (BR-6). Prohibited rather than ignored at this
            // layer so the refusal is legible in the response.
            'company_id' => [
                Rule::prohibitedIf(fn () => ! $this->user()?->isSuperAdmin()),
                'nullable', 'integer', Rule::exists('companies', 'id'),
            ],

            // The printed part of the URL. Lowercase letters, digits and
            // hyphens only: this goes on a flyer and gets typed back in, so
            // anything that needs percent-encoding or a shift key is out.
            // Minimum 4 so a code cannot be short enough to guess by hand.
            'code' => [
                'nullable', 'string', 'min:4', 'max:64',
                'regex:/^[a-z0-9][a-z0-9-]*[a-z0-9]$/',
                Rule::unique('company_invite_codes', 'code'),
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['present', 'nullable', 'date', 'after:now'],
            'max_uses' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'รหัสลิงก์ใช้ได้เฉพาะ a-z, 0-9 และ - เท่านั้น และต้องขึ้นต้น/ลงท้ายด้วยตัวอักษรหรือตัวเลข',
            'code.unique' => 'รหัสนี้ถูกใช้ไปแล้ว กรุณาใช้รหัสอื่น',
            'expires_at.after' => 'วันหมดอายุต้องเป็นเวลาในอนาคต',
            'max_uses.min' => 'จำนวนครั้งที่ใช้ได้ต้องอย่างน้อย 1 ครั้ง (เว้นว่างไว้ = ไม่จำกัด)',
        ];
    }
}
