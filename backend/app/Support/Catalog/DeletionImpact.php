<?php

namespace App\Support\Catalog;

use App\Models\Brand;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\CompanyProductSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Referral;
use App\Models\Scopes\TenantScope;
use App\Support\DeletionGuard;

/**
 * 2026-09-09 (human: "ตอนนี้ไม่มี Ui ลบสินค้ากลาง กับสินค้าจาก company ผู้ใช้
 * ไม่ทราบ ควรแยกกัน" — and, on what should stop a delete: "เตือนหากยังไม่มี
 * การขายเกิดขึ้น ห้ามลบกรณีมีการขายเกิดขึ้นแล้ว").
 *
 * What deleting this catalogue row would actually do — asked BEFORE the
 * delete, and asked again while performing it.
 *
 * ── WHY ONE CLASS AND NOT TWO ──
 *
 * The confirmation dialog and the enforcement have to agree, and the only
 * reliable way to make two things agree is to make them one thing. Before
 * this, the dialog said "ถ้ามีการขาย/คอมมิชชั่นผูกอยู่ ระบบจะไม่ยอมให้ลบ" —
 * a guess about the server's behaviour, written by hand in the template,
 * with nothing keeping it true. This class answers the question, the
 * controller enforces the same answer, and the dialog prints it.
 *
 * ── BLOCKERS AND WARNINGS ARE DIFFERENT THINGS ──
 *
 *   BLOCKERS are HISTORY. A sale, a commission ledger row: money that
 *   already moved (BR-4, immutable). Hiding the product they name would
 *   leave a paid commission describing a package no report can resolve, so
 *   the delete is refused outright.
 *
 *   WARNINGS are STATE. "Three companies currently have this switched on"
 *   is a fact the person must see, not a reason to refuse: a switch is not
 *   history, and requiring them to flip three switches off first would be
 *   ceremony rather than safety. But deleting a shared row silently takes it
 *   off three storefronts at once, and that must never be a surprise.
 *
 * A platform product is the whole reason warnings exist as a category. For a
 * company-owned product there is exactly one company and it is the one the
 * admin is looking at; for a shared one there may be five, and none of them
 * are on screen.
 */
final class DeletionImpact
{
    /**
     * @return array{is_shared: bool, blockers: array<string, int>, selling_companies: list<string>}
     */
    public static function forProduct(Product $product): array
    {
        return [
            'is_shared' => $product->isShared(),
            // TASK-091's original four, unchanged. Referrals and the ledger
            // are first because they are the BR-4 money records.
            'blockers' => [
                'Referral / การขาย' => Referral::query()->where('product_id', $product->id)->count(),
                'รายการคอมมิชชั่น' => CommissionLedger::query()->where('product_id', $product->id)->count(),
                'อัตราคอมมิชชั่น' => $product->commissionRules()->count(),
                'บทเรียน Academy' => $product->modules()->count(),
            ],
            'selling_companies' => self::sellingCompanies($product),
        ];
    }

    /**
     * @return array{is_shared: bool, blockers: array<string, int>, selling_companies: list<string>}
     */
    public static function forBrand(Brand $brand): array
    {
        return [
            'is_shared' => $brand->company_id === null,
            'blockers' => ['สินค้า' => $brand->products()->count()],
            // A brand is never "on sale" anywhere itself — it is reached
            // through its products, and those are the blocker above.
            'selling_companies' => [],
        ];
    }

    /**
     * @return array{is_shared: bool, blockers: array<string, int>, selling_companies: list<string>}
     */
    public static function forCategory(ProductCategory $category): array
    {
        return [
            'is_shared' => $category->company_id === null,
            'blockers' => [
                'สินค้า' => $category->products()->count(),
                'อัตราคอมมิชชั่นที่ผูกกับหมวดหมู่นี้' => CommissionRule::query()
                    ->where('product_category_id', $category->id)
                    ->count(),
            ],
            'selling_companies' => [],
        ];
    }

    /**
     * The companies that would lose this product from their storefront the
     * moment it is hidden.
     *
     * Only meaningful for a shared product: a company-owned one has exactly
     * one company, and it is the company the admin is already looking at.
     *
     * Unscoped on purpose — the point is to name companies OTHER than the
     * current scope, which is precisely what the tenant scope hides. Only a
     * Super Admin can reach a delete on a shared row (ProductPolicy), and a
     * company NAME is the least that can be said while still making the
     * consequence legible.
     *
     * @return list<string>
     */
    private static function sellingCompanies(Product $product): array
    {
        if (! $product->isShared()) {
            return [];
        }

        $ids = CompanyProductSetting::withoutGlobalScope(TenantScope::class)
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->pluck('company_id');

        if ($ids->isEmpty()) {
            return [];
        }

        return Company::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * Refuse the delete if anything in `blockers` is non-zero. The warnings
     * are deliberately NOT consulted here — see the class docblock.
     *
     * @param  array{blockers: array<string, int>, ...}  $impact
     */
    public static function enforce(array $impact): void
    {
        DeletionGuard::ensureNoDependents($impact['blockers']);
    }
}
