<?php

namespace App\Http\Requests\Platform;

use App\Enums\SupplierGpMode;
use App\Enums\SupplierReleaseTrigger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 2026-09-17 — creating a supplier.
 *
 * ── SUPER ADMIN ONLY ──
 *
 * Every deal term here decides money: who receives it, how much we keep, when
 * it becomes payable, and which bank account it lands in. A Company Admin
 * setting a GP would be setting our margin on sales their own company makes.
 * And a supplier is not a tenant's property — its products are sold by every
 * company on the platform.
 *
 * ── A HALF-FILLED DEAL SAVES ──
 *
 * Only `name` is required. Everything else is nullable, because a deal
 * negotiated in stages is the normal case: somebody records the counterparty
 * on Monday, the GP is agreed on Thursday, and the bank details arrive when
 * accounting asks for them.
 *
 * Refusing to save until all of it is present does not produce a complete
 * record — it produces a note in somebody's notebook. The payout screen is
 * what refuses to PAY an incomplete supplier, and it says which part is
 * missing (Supplier::missingTerms()).
 */
class StoreSupplierRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            // Thai juristic-person tax IDs are 13 digits; the column is wider
            // and this rule is looser, because a foreign supplier's is not.
            'tax_id' => ['nullable', 'string', 'max:32'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],

            ...self::termRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => self::validateTerms($this, $v));
    }

    /**
     * The deal-term half of the form, shared with UpdateSupplierRequest.
     *
     * A static method rather than a trait because there are exactly two
     * callers and they are siblings; a trait would put the rules a third file
     * away from both.
     *
     * @return array<string, mixed>
     */
    public static function termRules(): array
    {
        return [
            'gp_mode' => ['nullable', Rule::enum(SupplierGpMode::class)],
            // Basis points for the percentage modes, satang for the fixed one.
            // One column, one rule — see the migration for why they share.
            'gp_value' => ['nullable', 'integer', 'min:0'],
            'release_trigger' => ['nullable', Rule::enum(SupplierReleaseTrigger::class)],
            'min_withdrawal_satang' => ['nullable', 'integer', 'min:0'],
            // Basis points. 10000 = 100%, which is absurd but is the ceiling
            // the column can hold; the realistic values are 0 and 300.
            'wht_rate' => ['nullable', 'integer', 'min:0', 'max:10000'],

            'payout_bank_name' => ['nullable', 'string', 'max:255'],
            'payout_bank_account_number' => ['nullable', 'string', 'max:255'],
            'payout_bank_account_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The two cross-field rules, shared with UpdateSupplierRequest.
     */
    public static function validateTerms(FormRequest $request, Validator $validator): void
    {
        /*
         * Mode and value travel together, the same rule the product-level
         * override carries and for the same reason: a mode with no value falls
         * through to whatever else is set, and a percentage read as an amount
         * is wrong by a factor of a thousand while still looking like a number.
         *
         * `has()` rather than `input() === null`, so a PATCH that does not
         * mention either field is not treated as clearing both.
         */
        if (! $request->has('gp_mode') && ! $request->has('gp_value')) {
            return;
        }

        $mode = $request->input('gp_mode');
        $value = $request->input('gp_value');

        if (($mode === null) !== ($value === null)) {
            $validator->errors()->add(
                'gp_value',
                'ต้องระบุทั้งรูปแบบ GP และค่า GP คู่กัน หรือเว้นว่างทั้งคู่',
            );
        }

        if ($mode !== null
            && $mode !== SupplierGpMode::FixedPerUnit->value
            && (int) $value > 10000) {
            $validator->errors()->add('gp_value', 'GP แบบเปอร์เซ็นต์ต้องไม่เกิน 100%');
        }
    }
}
