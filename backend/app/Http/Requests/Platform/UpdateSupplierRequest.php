<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 2026-09-17 — editing a supplier.
 *
 * Same fields and same rules as StoreSupplierRequest, with `sometimes` on
 * every one so the screen can save a single section without sending the other
 * three back — the supplier form has four panels and a person editing the bank
 * details should not be able to blank the deal terms by omission.
 *
 * ── THE ONE RULE THAT IS NOT A FIELD ──
 *
 * Deactivating a supplier we still owe is allowed, and it is the payout screen
 * that keeps that safe by listing inactive suppliers with a balance rather
 * than hiding them. The first cut refused it, which sounds careful and is not:
 * a deal genuinely ends before the last invoice is settled, and a refusal just
 * means the flag never gets set and the supplier stays on the "current" list
 * forever. Blocking the wrong thing is how people learn to work around a
 * system.
 *
 * What IS refused is DELETING one with settlement history — see
 * SupplierController::destroy.
 */
class UpdateSupplierRequest extends FormRequest
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
        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:32'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        foreach (StoreSupplierRequest::termRules() as $field => $rule) {
            array_unshift($rule, 'sometimes');
            $rules[$field] = $rule;
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => StoreSupplierRequest::validateTerms($this, $v));
    }
}
