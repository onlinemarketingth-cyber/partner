<?php

namespace App\Http\Requests\Catalog\Concerns;

use App\Enums\CommissionRateType;
use App\Models\Product;
use Illuminate\Contracts\Validation\Validator;

/**
 * TASK-197 §2.2 — the ONE place StoreCommissionRuleRequest and
 * UpdateCommissionRuleRequest both check "does this rule's rate_type match
 * the %/fixed-amount FORMAT already locked in on this product". Mirrors
 * ValidatesCommissionRateCap's shape (TASK-196) on purpose: same
 * validate-only contract, no write here. The "first rule for this product
 * decides the format" side effect (product.commission_rate_type still null
 * -> stamp it from the incoming rate_type) is a DB write and belongs in
 * CommissionRuleService::create(), inside the SAME transaction as the rule
 * INSERT — never in a Form Request (CLAUDE.md §7 layering: Form Request
 * validates, Service does business logic/writes).
 *
 * Category-scoped and company-wide rules (product_id null) are completely
 * exempt — TASK-197 §1/§2.2: "There is no single product to hoist a format
 * onto for those."
 */
trait ValidatesCommissionRateTypeConsistency
{
    protected function assertRateTypeConsistentWithProduct(
        Validator $validator,
        ?int $productId,
        string|CommissionRateType|null $rateType,
    ): void {
        if ($productId === null || $rateType === null) {
            return;
        }

        $product = Product::query()->find($productId);

        // Invalid product_id is already caught by the Rule::exists() rule
        // in the base rules() — nothing more to check against a product
        // that doesn't exist.
        if ($product === null) {
            return;
        }

        // Null = "not yet configured for this product" — the incoming
        // rate_type is accepted as given; CommissionRuleService::create()
        // stamps it onto the product as a side effect of this first rule.
        if ($product->commission_rate_type === null) {
            return;
        }

        $rateTypeEnum = $rateType instanceof CommissionRateType ? $rateType : CommissionRateType::tryFrom($rateType);

        if ($rateTypeEnum === null || $rateTypeEnum === $product->commission_rate_type) {
            return;
        }

        $configuredLabel = $product->commission_rate_type === CommissionRateType::Percentage
            ? '% ของยอดขาย'
            : 'จำนวนคงที่ (บาท)';

        $validator->errors()->add(
            'rate_type',
            "สินค้านี้ถูกตั้งค่ารูปแบบอัตราคอมมิชชั่นไว้แล้วเป็น \"{$configuredLabel}\" กรุณาเลือกรูปแบบเดียวกันสำหรับทุก tier ของสินค้านี้",
        );
    }
}
