<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateCompanyProductSettingRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Resources\CompanyProductSettingResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Catalog\CompanyProductSettingService;
use App\Services\Catalog\ProductGradingService;
use App\Services\Catalog\ProductRecommendationService;
use App\Services\Catalog\ProductService;
use App\Support\Catalog\DeletionImpact;
use App\Support\CompanyScopeFilter;
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
        /*
         * TASK-253 / ADR-040 — includePlatformWide, because a NULL company_id
         * here now means "the platform owns this row and every company uses
         * it", exactly as it already did for announcements and reward items.
         *
         * Without it the picker would CONTRADICT the tenant scope: a Company
         * Admin (SharedOrTenantScope) sees the shared rows, while a Super
         * Admin who narrows to that same company does not — so the person
         * with more authority sees less, and would reasonably conclude the
         * shared product is missing from that company.
         */
        CompanyScopeFilter::apply($query, $request, includePlatformWide: true);

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

            /*
             * TASK-254 / ADR-040 — and, for a PLATFORM-owned product, this
             * agent's own company must have switched it on.
             *
             * Enforced in SQL rather than by filtering the page afterwards:
             * this endpoint paginates, so a client-side pass would answer
             * "the sellable products that happen to be on page 1 of all of
             * them" — the same class of lie TASK-202 fixed for the company
             * scope. A company-owned product is untouched by the clause.
             */
            $companyId = $request->user()->company_id;

            $query->where(fn ($outer) => $outer
                ->whereNotNull('products.company_id')
                ->orWhereExists(fn ($exists) => $exists
                    ->selectRaw('1')
                    ->from('company_product_settings')
                    ->whereColumn('company_product_settings.product_id', 'products.id')
                    ->where('company_product_settings.company_id', $companyId)
                    ->where('company_product_settings.is_active', true)));
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
        // TASK-254 / ADR-040 — `isSellableBy`, not `is_active`: for a shared
        // product the agent's own company decides, and a product their company
        // has not switched on must be as invisible here as a deactivated one.
        abort_if(
            $request->user()?->isAgent() && ! $product->isSellableBy($request->user()->company_id),
            404,
        );

        // `media` is loaded here as of 2026-08-21: ProductResource wraps
        // `thumbnail_url` in when(relationLoaded('media')), so without it the
        // key was simply ABSENT from this endpoint — index() loaded the
        // relation and show() never did, which nothing noticed until the new
        // agent-facing product detail page asked show() for a picture and got
        // no key at all rather than a null. Ordered the same way index()
        // orders it so both endpoints resolve the same cover.
        return new ProductResource($product->load([
            'brand', 'category', 'company', 'catalogItem.catalogBrand', 'catalogItem.catalogCategory',
            'media' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order'),
        ]));
    }

    public function update(UpdateProductRequest $request, Product $product, ProductService $service): ProductResource
    {
        $product = $service->update($product, $request->validated());

        return new ProductResource($product->load(['brand', 'category', 'company', 'catalogItem.catalogBrand', 'catalogItem.catalogCategory']));
    }

    /**
     * GET /products/{product}/deletion-impact — what deleting this would do,
     * asked before the confirmation dialog is drawn (2026-09-09).
     *
     * The dialog used to state the rules from memory ("ถ้ามีการขาย/คอมมิชชั่น
     * ผูกอยู่ ระบบจะไม่ยอมให้ลบ") — a sentence in a template with nothing
     * keeping it true, and one that could not name the OTHER companies a
     * shared product would vanish from. Now it prints this.
     *
     * Authorized as 'delete': asking what a delete would cost is only for
     * somebody who could perform it, and for a shared product that is Super
     * Admin alone.
     */
    public function deletionImpact(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        return response()->json(['data' => DeletionImpact::forProduct($product)]);
    }

    public function destroy(Product $product): Response
    {
        // TASK-091 — a product is the most-referenced row in the catalogue.
        // The same computation the dialog was drawn from, so the two can
        // never drift apart (see DeletionImpact's own docblock).
        DeletionImpact::enforce(DeletionImpact::forProduct($product));

        $product->delete();

        return response()->noContent();
    }

    /**
     * POST /products/{product}/restore — TASK-091 always soft-deleted, and
     * until now nothing on any screen could bring a row back: the data was
     * still there and the only way to reach it was the database.
     *
     * Route binds withTrashed(), same shape as users.restore.
     */
    public function restore(Product $product): ProductResource
    {
        $this->authorize('restore', $product);

        $product->restore();

        return new ProductResource($product->load(['brand', 'category', 'company']));
    }

    /**
     * PUT /products/{product}/company-settings — TASK-254 / ADR-040.
     *
     * What ONE company charges for a SHARED product, and whether it is on sale
     * there. Super-Admin-only (UpdateCompanyProductSettingRequest), because
     * that is the human's decision in both ADR-036 and ADR-040.
     *
     * Refused for a company-owned product, deliberately: that row already has
     * exactly one company and its price lives on the product itself. Accepting
     * it here would create a second place where a price could be set, and two
     * places to look is how the two disagree.
     */
    public function updateCompanySetting(
        UpdateCompanyProductSettingRequest $request,
        Product $product,
        CompanyProductSettingService $service,
    ): JsonResponse {
        abort_unless(
            $product->isShared(),
            422,
            'สินค้านี้เป็นของบริษัทเดียว — ตั้งราคาที่ตัวสินค้าโดยตรง ไม่ใช่ที่นี่',
        );

        $setting = $service->set(
            $product,
            $request->integer('company_id'),
            $request->safe()->except('company_id'),
            $request->user(),
        );

        /*
         * ALWAYS 200, never 201 — even though the underlying row may have just
         * been inserted.
         *
         * Laravel returns 201 for a resource whose model was recently created,
         * which would make this PUT answer 201 the first time a company is
         * priced and 200 every time after. The caller did not create anything:
         * "this company has not decided yet" and "this company decided" are
         * the same object in two states, and the row's existence is
         * bookkeeping. A status code that alternates is one every client has
         * to special-case.
         */
        return (new CompanyProductSettingResource($setting))->response()->setStatusCode(200);
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
