<?php

namespace App\Http\Requests\Catalog\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * 2026-09-09 (human: "ตอนนี้แบรนด์เหลืออันเดียวแล้ว และหมวดหมู่ด้วย แต่บันทึกแล้ว
 * error" — "The selected brand id is invalid.").
 *
 * Which brands and categories a product may point at.
 *
 * ── WHAT WAS WRONG ──
 *
 * Three Form Requests each wrote the same rule by hand:
 *
 *     Rule::exists('brands', 'id')->where('company_id', $companyId)
 *
 * — an exact match on the owning company, correct for as long as every brand
 * belonged to exactly one. ADR-040 ended that: a brand with `company_id NULL`
 * is owned by the PLATFORM and usable by every company, and BrandController
 * has been offering those rows in the picker since TASK-253
 * (`includePlatformWide: true`).
 *
 * So the form listed a brand it would then refuse to accept. It stayed hidden
 * while each company still had a same-named brand of its own to pick instead,
 * and surfaced the moment `catalog:tidy-taxonomy` cleared those away — leaving
 * a company that could not create a product at all, because every brand
 * offered to it was refused.
 *
 * ── THE OTHER HALF: SOFT-DELETED ROWS ──
 *
 * `Rule::exists` queries the table, not the model, so a soft-deleted brand
 * satisfied it. That was survivable while nothing deleted brands; the tidy
 * command deletes them by the dozen, and every one of those ids would still
 * have validated. `whereNull('deleted_at')` closes it.
 */
trait ValidatesProductTaxonomy
{
    /**
     * A brand/category the product's company may legitimately use: its own,
     * or one the platform owns. Never another tenant's (BR-6), and never a
     * deleted one.
     *
     * A NULL $companyId means the product itself is platform-owned, and the
     * two branches collapse into the same thing — platform rows only, which
     * is exactly right: a shared product cannot carry one company's brand.
     */
    protected function taxonomyRule(string $table, ?int $companyId): Exists
    {
        return Rule::exists($table, 'id')->where(
            fn ($query) => $query
                ->whereNull('deleted_at')
                ->where(fn ($scoped) => $scoped
                    ->where('company_id', $companyId)
                    ->orWhereNull('company_id')),
        );
    }
}
