<?php

namespace App\Http\Requests\Catalog\Concerns;

use App\Enums\SupplierGpMode;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierSettlementLedger;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 2026-09-16 — the supplier fields on a product, and the four rules that stop
 * them being used to move money by accident.
 *
 * Shared by StoreProductRequest and UpdateProductRequest because splitting
 * them would be two copies of the same money rules, and the create path is the
 * one somebody will forget to update.
 *
 * ── RULE 1: SUPER ADMIN ONLY ──
 *
 * These three fields decide who receives money and how much we keep. That is
 * not catalogue administration, and a Company Admin editing a platform product
 * has no business setting either. Same gate `company_id` and `pv_satang`
 * already use, and stated as `prohibitedIf` rather than silently dropped so a
 * console sending them gets told rather than wondering why nothing saved.
 *
 * ── RULE 2: A SUPPLIER ONLY MAKES SENSE ON A PLATFORM PRODUCT ──
 *
 * A product owned by one company (company_id set) is sold by that company
 * alone; there is nobody for a supplier arrangement to sit between. Allowing
 * it would create rows that look like supplier sales and are not.
 *
 * ── RULE 3: MODE AND VALUE TRAVEL TOGETHER ──
 *
 * A product that names a mode but no value falls back to the SUPPLIER's value,
 * and a 30% deal read as 30 satang is a number 1000x wrong that still looks
 * like a number. So the override is all-or-nothing, enforced rather than
 * documented.
 *
 * ── RULE 4: THE SUPPLIER FREEZES ONCE MONEY HAS MOVED ──
 *
 * Settled rows keep their own snapshot, so history is safe. What is not safe
 * is the next sale: changing the supplier silently redirects it to a different
 * counterparty, with nothing on the product to record that it ever moved. A product
 * that has sold under one supplier and now sells under another is two products
 * wearing one name, and the reconciliation nobody can do afterwards is the
 * reason this refuses instead of warning.
 */
trait HandlesSupplierTerms
{
    /**
     * @return array<string, mixed>
     */
    protected function supplierTermRules(): array
    {
        $superAdminOnly = Rule::prohibitedIf(fn () => ! $this->user()->isSuperAdmin());

        return [
            'supplier_id' => [
                'sometimes',
                'nullable',
                $superAdminOnly,
                'integer',
                /*
                 * Must be a supplier that is still trading. `exists` alone
                 * would accept an ended deal, and a product listed against one
                 * is a product whose sales nobody is going to be paid for.
                 *
                 * Soft-deleted rows are excluded by the same clause, since the
                 * query builder here does not apply the model's scope.
                 */
                Rule::exists('suppliers', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'supplier_gp_mode' => [
                'sometimes',
                'nullable',
                $superAdminOnly,
                Rule::enum(SupplierGpMode::class),
            ],
            'supplier_gp_value' => [
                'sometimes',
                'nullable',
                $superAdminOnly,
                'integer',
                'min:0',
            ],
            'supplier_wht_rate' => [
                'sometimes',
                'nullable',
                $superAdminOnly,
                'integer',
                'min:0',
                // 10000 basis points = 100%. A rate above that withholds more
                // than the payment.
                'max:10000',
            ],
        ];
    }

    /**
     * Rules 2–4, which need the whole payload (and, on update, the existing
     * row) rather than one field at a time.
     */
    protected function validateSupplierTerms(Validator $validator, ?Product $product = null): void
    {
        $validator->after(function (Validator $validator) use ($product) {
            $supplierId = $this->input('supplier_id', $product?->supplier_id);

            // RULE 3 — never one without the other.
            $mode = $this->input('supplier_gp_mode', $product?->supplier_gp_mode?->value);
            $value = $this->input('supplier_gp_value', $product?->supplier_gp_value);

            if (($mode === null) !== ($value === null)) {
                $validator->errors()->add(
                    'supplier_gp_value',
                    'ต้องระบุทั้งรูปแบบ GP และค่า GP คู่กัน หรือเว้นว่างทั้งคู่เพื่อใช้ค่าของสัญญา',
                );
            }

            if ($supplierId === null) {
                return;
            }

            // RULE 2 — platform products only. On create, company_id absent
            // means the actor's own company, which is by definition not a
            // platform product.
            $companyId = $this->has('company_id')
                ? $this->input('company_id')
                : ($product ? $product->company_id : $this->user()->company_id);

            if ($companyId !== null) {
                $validator->errors()->add(
                    'supplier_id',
                    'ตั้งคู่ค้าได้เฉพาะสินค้าของแพลตฟอร์มเท่านั้น (สินค้าที่ไม่ได้เป็นของบริษัทใดบริษัทหนึ่ง)',
                );
            }

            // RULE 4 — frozen once this product has settlement history.
            if ($product
                && $product->supplier_id !== null
                && (int) $supplierId !== (int) $product->supplier_id
                && SupplierSettlementLedger::where('product_id', $product->id)->exists()) {
                $validator->errors()->add(
                    'supplier_id',
                    'สินค้านี้มีประวัติการคืนยอดให้คู่ค้ารายเดิมแล้ว จึงเปลี่ยนคู่ค้าไม่ได้ — ให้สร้างสินค้าใหม่แทน',
                );
            }
        });
    }

    /**
     * A supplier product with no GP anywhere cannot be sold.
     *
     * Called when `is_active` is being turned on. SupplierSettlementService
     * throws rather than guessing a GP (BR-7), and the place to find that out
     * is here — when somebody lists the product — not at 2am when a customer's
     * payment confirmation blows up halfway through a transaction.
     */
    protected function assertSellableSupplierTerms(Validator $validator, ?Product $product = null): void
    {
        $validator->after(function (Validator $validator) use ($product) {
            $active = $this->has('is_active') ? $this->boolean('is_active') : ($product?->is_active ?? false);
            $supplierId = $this->input('supplier_id', $product?->supplier_id);

            if (! $active || $supplierId === null) {
                return;
            }

            $hasProductTerms = ($this->input('supplier_gp_mode', $product?->supplier_gp_mode?->value) !== null)
                && ($this->input('supplier_gp_value', $product?->supplier_gp_value) !== null);

            if ($hasProductTerms) {
                return;
            }

            $supplier = Supplier::find($supplierId);

            if ($supplier === null || ! $supplier->hasCompleteTerms()) {
                $validator->errors()->add(
                    'supplier_id',
                    'คู่ค้ารายนี้ยังไม่ได้ตั้งเงื่อนไข GP หรือจังหวะการเบิกไว้ — ตั้งค่าที่หน้าจัดการคู่ค้าก่อน จึงจะเปิดขายสินค้านี้ได้',
                );
            }
        });
    }
}
