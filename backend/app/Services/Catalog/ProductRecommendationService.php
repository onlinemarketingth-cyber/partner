<?php

namespace App\Services\Catalog;

use App\Models\CompanyThemeSetting;
use App\Models\Product;
use App\Models\ProductRecommendationPin;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * TASK-068 / ADR-020 decision #1 (human-confirmed 2026-07-31) — the
 * "recommended for you" row's hybrid assembly logic: admin-pinned
 * products first (ordered by sort_order), then any remaining slots are
 * filled live from ProductGradingService's existing "A" grade output
 * (TASK-040 — never duplicated/re-derived here), excluding already-pinned
 * products, ordered by estimated sales volume desc (that Service's own
 * sort order). Slot count is BR-7 admin config
 * (CompanyThemeSetting::recommended_slot_count) — never a hardcoded
 * constant inside this Service; DEFAULT_SLOT_COUNT below is only the
 * ADR-020-sanctioned fallback used when a company has no theme-settings
 * row yet, same shape as ThemeService::defaults().
 *
 * TenantScope on Product/ProductRecommendationPin/CompanyThemeSetting
 * already restricts every query below to the actor's own company_id
 * (Section 5 rule 2) — this Service adds no manual company_id filtering
 * on top, the same way ProductController::index()/show() don't either.
 */
class ProductRecommendationService
{
    // Public (not private) so ThemeResource can expose the same fallback
    // value it uses for a company with no theme-settings row yet — one
    // source of truth for the ADR-020-sanctioned default of 8, not a
    // second hardcoded copy (BR-7).
    public const DEFAULT_SLOT_COUNT = 8;

    public function __construct(private readonly ProductGradingService $gradingService) {}

    /**
     * @return Collection<int, Product>
     */
    public function recommended(User $actor): Collection
    {
        $slotCount = $this->resolveSlotCount($actor);

        $pinnedProducts = ProductRecommendationPin::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->limit($slotCount)
            ->with(['product.brand', 'product.category', 'product.company', 'product.media' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order')])
            ->get()
            ->pluck('product')
            ->filter(fn (?Product $product) => $product !== null && $product->is_active)
            ->values();

        $remainingSlots = $slotCount - $pinnedProducts->count();

        if ($remainingSlots <= 0) {
            return $pinnedProducts->take($slotCount)->values();
        }

        $excludedIds = $pinnedProducts->pluck('id')->all();

        $autoFillIds = $this->gradingService->computeGrades($actor, null)
            ->where('grade', 'A')
            ->reject(fn (array $row) => in_array($row['product_id'], $excludedIds, true))
            ->take($remainingSlots)
            ->pluck('product_id')
            ->all();

        // TASK-156 — `is_active`, same as the pinned half above filters for.
        // Without it the two halves of one row disagreed: a deactivated
        // product could not be PINNED into the recommended strip, but a
        // deactivated former best-seller walked back in through auto-fill,
        // because ProductGradingService ranks on historical revenue and a
        // discontinued product's revenue does not go away. An admin who
        // switched a product off would have seen it still being promoted.
        $autoFillProducts = Product::query()
            ->where('is_active', true)
            ->whereIn('id', $autoFillIds)
            ->with(['brand', 'category', 'company', 'media' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order')])
            ->get()
            // Preserve ProductGradingService's own descending-revenue
            // order (whereIn() does not guarantee row order).
            ->sortBy(fn (Product $product) => array_search($product->id, $autoFillIds, true))
            ->values();

        return $pinnedProducts->concat($autoFillProducts)->values();
    }

    private function resolveSlotCount(User $actor): int
    {
        if ($actor->company_id === null) {
            // Super Admin with no home company — no single company's
            // config applies; fall back to the ADR-020 default rather
            // than guessing which company's row to read.
            return self::DEFAULT_SLOT_COUNT;
        }

        return CompanyThemeSetting::query()
            ->where('company_id', $actor->company_id)
            ->value('recommended_slot_count') ?? self::DEFAULT_SLOT_COUNT;
    }
}
