<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-254 / ADR-040 — a company's price and on/off switch for a shared
 * product.
 *
 * SUPER ADMIN ONLY, and that is the human's answer given twice: in ADR-036's
 * decision table ("Super Admin เป็นคนตั้ง…ราคา") and again on 2026-09-05 when
 * ADR-040 was agreed. A Company Admin sees the result read-only.
 *
 * It is written out here as well as in the controller because this is the
 * endpoint that sets what a customer pays: a Form Request that authorizes by
 * omission is one line away from authorizing everybody.
 */
class UpdateCompanyProductSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Which company is being priced. Required rather than inferred:
            // the actor is a Super Admin with no company of their own, so
            // there is nothing to infer from, and guessing would price the
            // wrong tenant's catalogue.
            'company_id' => ['required', 'integer', 'exists:companies,id'],

            /*
             * BR-3 satang, and NULLABLE on purpose: null means "stop
             * overriding — use the central price". `present` so that omitting
             * the key (leave the price alone) stays different from sending
             * null (clear the override); the two are separate instructions
             * and the Service reads them separately.
             */
            'price_satang' => ['sometimes', 'present', 'nullable', 'integer', 'min:0'],

            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price_satang.integer' => 'ราคาไม่ถูกต้อง',
            'price_satang.min' => 'ราคาต้องไม่ติดลบ',
            'company_id.required' => 'ต้องระบุบริษัทที่ต้องการตั้งราคา',
        ];
    }
}
