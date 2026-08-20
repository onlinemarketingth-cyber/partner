<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-233 — editing a company signup link.
 *
 * `code` IS ABSENT ON PURPOSE, and prohibited so that sending it fails
 * loudly rather than being quietly dropped. The code is the printed part
 * of the URL; changing it does not edit the flyer that is already on the
 * wall, it kills it. Wanting a different code means wanting a different
 * link, and revoking this one and minting another says that honestly.
 *
 * `sometimes` here where the store request used `present`: an edit is a
 * partial statement about a thing that already exists, so an omitted key
 * means "leave it", not "unlimited". The service reads them with
 * array_key_exists for exactly that reason.
 */
class UpdateCompanyInviteCodeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['prohibited'],
            'company_id' => ['prohibited'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'max_uses' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.prohibited' => 'รหัสลิงก์เปลี่ยนไม่ได้ — ลิงก์เดิมอาจถูกพิมพ์แจกไปแล้ว หากต้องการรหัสใหม่ให้ปิดลิงก์นี้แล้วสร้างใหม่',
            'company_id.prohibited' => 'ย้ายลิงก์ข้ามบริษัทไม่ได้',
        ];
    }
}
