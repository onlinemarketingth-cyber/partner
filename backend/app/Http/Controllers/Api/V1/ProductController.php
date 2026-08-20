<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\CommissionLedger;
use App\Models\Product;
use App\Models\Referral;
use App\Services\Catalog\ProductGradingService;
use App\Services\Catalog\ProductRecommendationService;
use App\Services\Catalog\ProductService;
use App\Support\CompanyScopeFilter;
use App\Support\DeletionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Product::class, 'product');
    }

    // 'company' is eager-loaded alongside brand/category so
    // ProductResource::effectivePlanType() (ADR-011/TASK-027, reads
    // $product->company->commission_plan_type on inherit) never N+1s.
    //
    // TASK-056 P3 — added optional `q` (name search) and `is_active`
    // filters plus an optional `per_page` override for the new Agent
    // Portal Product browse screen (needs more than the 15-row admin
    // default to page through a full catalog client-side). 'media' is
    // eager-loaded (primary first) purely so ProductResource can expose
    // a `thumbnail_url` for the browse grid — none of this changes the
    // response shape for existing callers who don't pass the new params.
    //
    // TASK-068 / ADR-020 — added optional category_id/brand_id/
    // price_min_satang/price_max_satang filters for the storefront's row 1
    // search+filter bar, same inline validate() query-filter pattern as
    // AgentCommissionSummaryController::index(). BR-3: price_*_satang are
    // integers only. Existing q/is_active/per_page/pagination behaviour
    // is unchanged for callers who don't pass the new params.
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'category_id' => ['sometimes', 'integer', 'exists:product_categories,id'],
            'brand_id' => ['sometimes', 'integer', 'exists:brands,id'],
            'price_min_satang' => ['sometimes', 'integer', 'min:0'],
            'price_max_satang' => ['sometimes', 'integer', 'min:0', 'gte:price_min_satang'],
        ]);

        $query = Product::query()
            ->with([
                'brand', 'category', 'company',
                // ADR-036 §2/§3 (TASK-212) — needed for ProductResource's
                // 'brand'/'category' keys to resolve from the shared
                // catalog item when catalog_item_id is set, without an
                // N+1 per row.
                'catalogItem.catalogBrand', 'catalogItem.catalogCategory',
                'media' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order'),
            ])
            ->orderBy('name');

        // TASK-209 — Super Admin's header company scope, applied in SQL.
        CompanyScopeFilter::apply($query, $request);

        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->string('q')->trim().'%');
        }

        // TASK-156 §3 — "ปิดการใช้งาน ซ่อนทุกที่" (human, 2026-08-10).
        //
        // For an Agent this is a RULE, not the opt-in `?is_active=` filter it
        // used to be: a deactivated product cannot be browsed, searched or
        // picked, so the client cannot ask for it back by passing the
        // parameter. For an Admin the original opt-in behaviour is preserved
        // untouched — `?is_active=0` is how they find what they switched off,
        // which is the whole point of them being exempt.
        //
        // NOT a Global Scope on Product, deliberately (§3 boundary):
        // CommissionLedgerResource / OrderResource / ReferralResource all read
        // the LIVE `product` relation for the product's name (TASK-047
        // snapshotted the price, not the name), so a scope would render
        // `product: null` on an agent's own paid commission rows. Per-endpoint
        // filtering is what keeps "hidden where it can be chosen" from turning
        // into "blank where it already happened".
        if ($request->user()?->isAgent()) {
            $query->where('is_active', true);
        } elseif ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        if (isset($filters['price_min_satang'])) {
            $query->where('price_satang', '>=', $filters['price_min_satang']);
        }

        if (isset($filters['price_max_satang'])) {
            $query->where('price_satang', '<=', $filters['price_max_satang']);
        }

        return ProductResource::collection(
            $query->paginate($request->integer('per_page') ?: 15)
        );
    }

    public function store(StoreProductRequest $request, ProductService $service): ProductResource
    {
        $product = $service->create($request->validated(), $request->user());

        return new ProductResource($product->load(['brand', 'category', 'company', 'catalogItem.catalogBrand', 'catalogItem.catalogCategory']));
    }

    /**
     * TASK-156 §3 — the same rule as index(), applied to the single-product
     * read so the filter cannot be sidestepped by asking for the id directly.
     * Ids are sequential; "the list filters it" is not mitigation.
     *
     * 404, not 403 — consistent with CLAUDE.md §5.5 and with what TASK-155 did
     * for draft Sections: to an Agent a deactivated product does not exist, and
     * distinguishing "no such product" from "a product you may not see" is
     * itself the leak.
     *
     * This does NOT strand history. An Agent's own commission rows, orders and
     * client files name the product through the eager-loaded `product`
     * relation on their own Resources, not through this route (§3 boundary),
     * and neither do the nested /products/{id}/media, /specs and
     * /sales-materials routes, which are only reachable from a record the
     * caller already holds.
     */
    public function show(Request $request, Product $product): ProductResource
    {
        abort_if($request->user()?->isAgent() && ! $product->is_active, 404);

        return new ProductResource($product->load(['brand', 'category', 'company', 'catalogItem.catalogBrand', 'catalogItem.catalogCategory']));
    }

    public function update(UpdateProductRequest $request, Product $product, ProductService $service): ProductResource
    {
        $product = $service->update($product, $request->validated());

        return new ProductResource($product->load(['brand', 'category', 'company', 'catalogItem.catalogBrand', 'catalogItem.catalogCategory']));
    }

    public function destroy(Product $product): Response
    {
        // TASK-091 — a product is the most-referenced row in the catalogue.
        // Referrals and the commission ledger are listed first because they
        // are BR-4 money records: hiding the product they point at would
        // leave a paid commission describing a package no report can name.
        DeletionGuard::ensureNoDependents([
            'Referral / การขาย' => Referral::query()->where('product_id', $product->id)->count(),
            'รายการคอมมิชชั่น' => CommissionLedger::query()->where('product_id', $product->id)->count(),
            'อัตราคอมมิชชั่น' => $product->commissionRules()->count(),
            'บทเรียน Academy' => $product->modules()->count(),
        ]);

        $product->delete();

        return response()->noContent();
    }

    /**
     * GET /products-abc-grades?window_days=30|90|365 — Product-view IA
     * item 2.2. Gated the same as index() (viewAny Product) since it's
     * read-only aggregate reporting over the same data, not a new
     * resource. window_days omitted = all-time. See
     * ProductGradingService's docblock for the estimation/disclosure
     * discipline (no persisted "grade" field — always computed fresh).
     */
    public function abcGrades(Request $request, ProductGradingService $service): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $windowDays = $request->filled('window_days') ? $request->integer('window_days') : null;

        return response()->json([
            'data' => $service->computeGrades($request->user(), $windowDays)->values(),
            'window_days' => $windowDays,
            'computed_at' => now(),
        ]);
    }

    /**
     * GET /products/recommended — ADR-020 row 4 / TASK-068. Gated the
     * same as index() (viewAny Product) — it's a curated read view over
     * the same tenant-scoped Product data, not a new resource. All
     * assembly logic (pinned-then-auto-fill, slot-count resolution)
     * lives in ProductRecommendationService per CLAUDE.md §7 (business
     * logic never in a Controller).
     */
    public function recommended(Request $request, ProductRecommendationService $service): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        return ProductResource::collection($service->recommended($request->user()));
    }
}
