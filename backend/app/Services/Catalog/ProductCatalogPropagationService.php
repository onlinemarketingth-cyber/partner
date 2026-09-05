<?php

namespace App\Services\Catalog;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCatalogItem;
use App\Models\Scopes\TenantScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * TASK-251 (ADR-036 amendment, human decision 2026-09-04) — "สินค้าใช้ร่วมกัน
 * ทุกบริษัท".
 *
 * ── WHAT THIS CHANGES ABOUT ADR-036 ──
 *
 * ADR-036 §6 made linking a deliberate, one-company-at-a-time Super Admin
 * action: browse the catalog, pick a company, create or link a row. The human
 * asked for the opposite default — a product added to the shared catalog
 * should simply BE in every company — so this service does that half
 * automatically. What it does NOT do is decide anything ADR-036 reserved for
 * a person:
 *
 *   • every created row is `is_active = false`. The human's word was
 *     "ปิดไว้ก่อน", and it is also the only safe default: propagation reaches
 *     companies whose admins have never heard of this product, and a listing
 *     that goes live by itself is a product on sale that nobody chose to
 *     sell.
 *   • the price is the catalog item's `default_price_satang`, copied once.
 *     From that instant the value belongs to that company (ADR-036 §3) and
 *     nothing here ever writes it again — re-running propagation cannot
 *     reprice a company that has since set its own.
 *   • commission is not touched at all. The human considered a central
 *     default and said no: each company's commission stays exactly where it
 *     already lives (`commission_rules` per product, BR-2), so a propagated
 *     row simply has none until somebody sets one. A product with no rule
 *     falls back to the company default, which is the same thing that happens
 *     to any product created by hand today.
 *
 * ── IDEMPOTENT, BECAUSE IT RUNS FROM THREE PLACES ──
 *
 * A catalog item being created, a company being created, and an admin
 * re-running the backfill command all reach this class, and two of them can
 * easily happen for the same (company, item) pair. Every method skips a pair
 * that already exists — including one whose product was soft-deleted, which
 * is a company that deliberately removed the listing and must not have it
 * silently resurrected.
 *
 * ── WHY IT WRITES ITS OWN AUDIT ROWS ──
 *
 * Section 6 records what affects money and what a company sells. A row that
 * appears in six companies' catalogs because one person saved one form is
 * exactly the kind of event that is impossible to explain afterwards without
 * a trail — and the actor may be a console command with no user at all, which
 * is why actor_user_id is nullable here (same shape as
 * CreateSuperAdminCommand's rows).
 */
class ProductCatalogPropagationService
{
    public function __construct(private ProductCatalogLinkService $linkService) {}

    /**
     * Give every company a (disabled) listing of this catalog item.
     *
     * @return list<int> the company ids that gained a listing — empty when
     *                   every company already had one, which is the normal
     *                   result of a re-run and not a failure.
     */
    public function propagateItemToAllCompanies(ProductCatalogItem $catalogItem): array
    {
        if ($catalogItem->default_price_satang === null) {
            /*
             * BR-7. An item with no default price cannot be given to anybody:
             * there is no price to create the row with, and inventing one
             * (zero, or the cheapest existing product) would put a number
             * nobody chose on a screen where it reads as a decision.
             * StoreProductCatalogItemRequest requires the field, so this is
             * reachable only for rows that predate TASK-251.
             */
            return [];
        }

        $companyIds = Company::query()->pluck('id')->all();
        $created = [];

        foreach ($companyIds as $companyId) {
            if ($this->createListing($catalogItem, (int) $companyId) !== null) {
                $created[] = (int) $companyId;
            }
        }

        if ($created !== []) {
            $this->audit(
                'catalog_item.propagated',
                ProductCatalogItem::class,
                (int) $catalogItem->id,
                // NULL company on purpose: this one event happened to every
                // company at once, and picking one of them to carry the row
                // would make it invisible to the others' audit screens.
                null,
                [
                    'catalog_item_id' => (int) $catalogItem->id,
                    'catalog_item_name' => $catalogItem->name,
                    'company_ids' => $created,
                    'company_count' => count($created),
                    'price_satang' => $catalogItem->default_price_satang,
                    'is_active' => false,
                ],
            );
        }

        return $created;
    }

    /**
     * Give a company a (disabled) listing of every catalog item.
     *
     * Called when a company is created, in the SAME transaction as the
     * company itself — the precedent is CompanyService::create()'s pipeline
     * templates and theme presets, and the reasoning is identical: a tenant
     * that exists but is missing what every other tenant has looks healthy
     * right up until somebody asks why their catalog is empty.
     *
     * @return int how many listings were created.
     */
    public function propagateAllItemsToCompany(Company $company): int
    {
        $items = ProductCatalogItem::query()
            ->whereNotNull('default_price_satang')
            ->get();

        $created = 0;

        foreach ($items as $item) {
            if ($this->createListing($item, (int) $company->id) !== null) {
                $created++;
            }
        }

        if ($created > 0) {
            $this->audit(
                'company.catalog_provisioned',
                Company::class,
                (int) $company->id,
                (int) $company->id,
                [
                    'company_id' => (int) $company->id,
                    'catalog_item_count' => $created,
                    'is_active' => false,
                ],
            );
        }

        return $created;
    }

    /**
     * One company's listing of one catalog item, or null when it already has
     * one.
     *
     * TenantScope is bypassed on the existence check and on the insert for the
     * same reason ProductCatalogLinkService does it: the row belongs to the
     * COMPANY being provisioned, never to the actor — who is a Super Admin
     * (scope-free anyway) or a console command (no actor at all, for whom the
     * scope would silently match nothing and this method would then create a
     * duplicate on every run).
     */
    private function createListing(ProductCatalogItem $catalogItem, int $companyId): ?Product
    {
        $exists = Product::withoutGlobalScope(TenantScope::class)
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('catalog_item_id', $catalogItem->id)
            ->exists();

        if ($exists) {
            return null;
        }

        return DB::transaction(function () use ($catalogItem, $companyId) {
            /*
             * Created with the local identity columns still empty, then handed
             * to ProductCatalogLinkService — the one place products.catalog_
             * item_id is ever written (its own docblock), and the place that
             * mirrors the catalog's brand/category into per-company rows.
             *
             * That mirroring is not cosmetic: TASK-206 found that a linked
             * product with a null category_id silently loses its
             * category-scoped commission rule (a wrong payout, BR-2, written
             * to an immutable ledger, BR-4) and its pipeline template, and
             * becomes unfindable by brand or category. Going through the same
             * service means a propagated product cannot quietly miss what a
             * hand-linked one gets.
             */
            $product = Product::withoutGlobalScope(TenantScope::class)->create([
                'company_id' => $companyId,
                'catalog_item_id' => $catalogItem->id,
                'price_satang' => $catalogItem->default_price_satang,
                // "ปิดไว้ก่อน" — the whole point. Each company turns its own
                // listing on when it has decided to sell it.
                'is_active' => false,
            ]);

            return $this->linkService->link($product, $catalogItem);
        });
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function audit(string $action, string $auditableType, int $auditableId, ?int $companyId, array $newValues): void
    {
        AuditLog::create([
            'company_id' => $companyId,
            // Null when a console command did this. The trail says what
            // happened either way; pretending a user did it would be worse
            // than admitting nobody was signed in.
            'actor_user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => null,
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
        ]);
    }
}
