<?php

namespace App\Http\Requests\Platform;

use App\Enums\SupplierGpMode;
use App\Enums\SupplierReleaseTrigger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 2026-09-17 — the deal with one supplier company, as a form.
 *
 * ── WHY THIS EXISTS AT ALL ──
 *
 * The supplier feature shipped with no way to turn a company INTO a supplier.
 * Every column was built, every service read them, and the only way to set one
 * was to edit the database by hand — so the whole chain was unusable from the
 * first step: no supplier company meant an empty supplier picker on the
 * product form, which meant no supplied products, which meant nothing to pay.
 *
 * Found by the owner asking where the screen was. Worth recording as a class
 * of mistake rather than a one-off: the tests all passed because they built
 * their own fixtures, and fixtures never walk through the setup path a person
 * has to walk through.
 *
 * ── SUPER ADMIN ONLY ──
 *
 * Every field here decides money — who receives it, how much we keep, when it
 * is payable, and which account it lands in. A Company Admin setting their own
 * company's GP would be setting our margin on their own sales.
 *
 * ── THE ONE RULE THAT IS NOT A FIELD ──
 *
 * `is_supplier` cannot be turned OFF while settlement rows exist. The rows
 * keep their own snapshots so history survives, but the payout screen lists
 * suppliers by this flag — clearing it would hide a company we still owe
 * money, with the debt intact and no screen showing it.
 */
class UpdateSupplierTermsRequest extends FormRequest
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
            'is_supplier' => ['required', 'boolean'],

            // All nullable: a deal being set up in stages is normal, and the
            // payout screen already refuses to pay a supplier whose terms are
            // incomplete (and says which part is missing). Refusing to SAVE a
            // half-filled form would just make somebody keep it in a notebook.
            'supplier_gp_mode' => ['nullable', Rule::enum(SupplierGpMode::class)],
            // Basis points for the percentage modes, satang for the fixed one.
            // 10000 bp = 100%; above that we would be keeping more than the
            // customer paid.
            'supplier_gp_value' => ['nullable', 'integer', 'min:0'],
            'supplier_release_trigger' => ['nullable', Rule::enum(SupplierReleaseTrigger::class)],
            'supplier_min_withdrawal_satang' => ['nullable', 'integer', 'min:0'],
            'supplier_wht_rate' => ['nullable', 'integer', 'min:0', 'max:10000'],

            // The account we transfer INTO. Deliberately not `payment_bank_*`
            // — see the migration.
            'supplier_payout_bank_name' => ['nullable', 'string', 'max:255'],
            'supplier_payout_bank_account_number' => ['nullable', 'string', 'max:255'],
            'supplier_payout_bank_account_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /*
             * Mode and value travel together, the same rule the product-level
             * override carries and for the same reason: a mode with no value
             * falls through to whatever else is set, and a percentage read as
             * an amount is wrong by a factor of a thousand while still looking
             * like a number.
             */
            $mode = $this->input('supplier_gp_mode');
            $value = $this->input('supplier_gp_value');

            if (($mode === null) !== ($value === null)) {
                $validator->errors()->add(
                    'supplier_gp_value',
                    'ต้องระบุทั้งรูปแบบ GP และค่า GP คู่กัน หรือเว้นว่างทั้งคู่',
                );
            }

            if ($mode !== null
                && $mode !== SupplierGpMode::FixedPerUnit->value
                && (int) $value > 10000) {
                $validator->errors()->add('supplier_gp_value', 'GP แบบเปอร์เซ็นต์ต้องไม่เกิน 100%');
            }
        });
    }
}
