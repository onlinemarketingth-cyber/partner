<?php

namespace App\Http\Requests\Academy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TASK-221 — editing a cert tier.
 *
 * `key` is accepted but the SERVICE refuses to change it once anything
 * depends on the tier (see CertTierService::update) — that check needs the
 * database, not a validation rule, and it must run for any caller, not
 * only this route.
 */
class UpdateCertTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('cert_tier'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => [
                'sometimes', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('cert_tiers', 'key')->ignore($this->route('cert_tier')?->id),
            ],
            'name' => ['sometimes', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_mandatory' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex' => 'รหัส (key) ใช้ได้เฉพาะ a-z, 0-9 และ _ เท่านั้น',
            'key.unique' => 'รหัส (key) นี้ถูกใช้ไปแล้ว',
        ];
    }
}
