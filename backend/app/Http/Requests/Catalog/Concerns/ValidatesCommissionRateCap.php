<?php

namespace App\Http\Requests\Catalog\Concerns;

use App\Enums\CommissionRateType;
use App\Models\Product;
use App\Services\Platform\PlatformCommissionSettingService;
use Illuminate\Contracts\Validation\Validator;

/**
 * TASK-196 §2.3 — the ONE place StoreCommissionRuleRequest and
 * UpdateCommissionRuleRequest both compute "does this rate_type/rate_value
 * imply a commission over the configured cap, against this product's
 * CURRENT price_satang". Shared so the boundary math exists exactly once
 * (spec's own "not 3 copies" instruction, applied to the backend side of
 * this feature — the frontend gets its own single composable for the
 * same reason).
 *
 * JUDGMENT CALL (CLAUDE.md §8 rule 1 — flagged, not silent): the spec's
 * §2.3 wording is "given rate_type, rate_value, and the rule's product_id
 * ... compute the implied commission amount against that product's
 * CURRENT price_satang." A commission_rule may also be scoped to a
 * product_category (product_id null) or be a company-wide default (both
 * null) — ADR-011/TASK-028. There is no single product price to check a
 * category/company-wide rule against, so this check is a no-op when
 * product_id is null. If there is an appetite to extend the cap to
 * category/company-wide rules later (e.g. against the highest-priced
 * product in scope), that needs its own human decision — the human's
 * screenshot and TASK-196 §1's scope were both about a specific product's
 * rate.
 */
trait ValidatesCommissionRateCap
{
    protected function assertWithinCommissionRateCap(
        Validator $validator,
        ?int $productId,
        string|CommissionRateType|null $rateType,
        ?int $rateValue,
    ): void {
        if ($productId === null || $rateType === null || $rateValue === null) {
            return;
        }

        $product = Product::query()->find($productId);

        // Invalid product_id is already caught by the Rule::exists() rule
        // in StoreCommissionRuleRequest::rules() — nothing more to check
        // here against a product that doesn't exist/isn't found.
        if ($product === null) {
            return;
        }

        $rateTypeEnum = $rateType instanceof CommissionRateType ? $rateType : CommissionRateType::tryFrom($rateType);

        if ($rateTypeEnum === null) {
            return;
        }

        $capBasisPoints = app(PlatformCommissionSettingService::class)->capBasisPoints();

        // §2.3 — "regardless of rate_type." Both branches are compared in
        // the SAME unit (basis points) to avoid a float division:
        //   - percentage: rate_value already IS basis points (BR-3-adjacent
        //     — see CommissionRule's own docblock), compared directly.
        //   - fixed_satang: cross-multiplied (rate_value * 10000 vs.
        //     capBasisPoints * price_satang) instead of dividing, so the
        //     boundary is exact integer arithmetic — a rate implying
        //     EXACTLY the cap is never pushed over it by a rounding step.
        $exceedsCap = match ($rateTypeEnum) {
            CommissionRateType::Percentage => $rateValue > $capBasisPoints,
            CommissionRateType::FixedSatang => $rateValue * 10000 > $capBasisPoints * $product->price_satang,
        };

        if (! $exceedsCap) {
            return;
        }

        $capPercentText = rtrim(rtrim(number_format($capBasisPoints / 100, 2), '0'), '.');
        $priceBahtText = number_format($product->price_satang / 100, 2);
        $enteredText = $rateTypeEnum === CommissionRateType::Percentage
            ? number_format($rateValue / 100, 2).'%'
            : number_format($rateValue / 100, 2).' บาท';

        $validator->errors()->add(
            'rate_value',
            "อัตราคอมมิชชั่นที่กรอกไว้ ({$enteredText}) เกิน {$capPercentText}% ของราคาขายสินค้านี้ ({$priceBahtText} บาท) กรุณาแก้ไขก่อนบันทึก",
        );
    }
}
