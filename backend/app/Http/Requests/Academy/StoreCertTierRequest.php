<?php

namespace App\Http\Requests\Academy;

use App\Models\CertTier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-221 — creating a cert tier.
 *
 * BR-7: no defaults for `key`, `name` or `is_mandatory` are supplied here
 * or anywhere below. CLAUDE.md §2 documents "Basic (mandatory) ->
 * Intermediate -> High" as the platform's intended shape, but which tiers
 * a deployment actually has is the operator's decision, and a Form Request
 * that pre-fills one is a Form Request that decides it for them.
 */
class StoreCertTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CertTier::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Lowercase a-z0-9_ only, and UNIQUE. This is the stable handle
            // support queries and seeders match on (`where('key','basic')`),
            // so it must survive being typed by a human: no spaces, no case
            // to get wrong, nothing that needs escaping in a URL.
            'key' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', 'unique:cert_tiers,key'],
            'name' => ['required', 'string', 'max:100'],
            // Optional — the Service assigns the next free slot when it is
            // omitted, rather than letting two tiers share 0 (which makes
            // every "highest passed tier" query order arbitrarily).
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
            'key.regex' => 'รหัส (key) ใช้ได้เฉพาะ a-z, 0-9 และ _ เท่านั้น เช่น basic, intermediate',
            'key.unique' => 'รหัส (key) นี้ถูกใช้ไปแล้ว',
        ];
    }
}
