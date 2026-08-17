<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreStorefrontBannerRequest;
use App\Http\Requests\Catalog\UpdateStorefrontBannerRequest;
use App\Http\Resources\StorefrontBannerResource;
use App\Models\StorefrontBanner;
use App\Services\Catalog\StorefrontBannerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// TASK-068 / ADR-020 row 2 — full CRUD, GET readable by any authenticated
// company user (the Agent Portal storefront carousel), write gated by
// StorefrontBannerPolicy (Company Admin/Super Admin only). Every action
// is Policy-gated via authorizeResource; TenantScope handles the
// query-level tenant filter (BR-6).
class StorefrontBannerController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(StorefrontBanner::class, 'storefront_banner');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StorefrontBanner::query()
            ->with(['product.media' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order')])
            ->orderBy('sort_order');

        // TASK-156 §3 — "ปิดการใช้งาน ซ่อนทุกที่" (human, 2026-08-10). A
        // deactivated banner is a rule for an Agent (the storefront carousel is
        // pure discovery); for an Admin the existing opt-in `?is_active=`
        // filter is preserved, since the Banners tab is where they toggle it.
        if ($request->user()?->isAgent()) {
            $query->where('is_active', true);

            /*
             * ...AND the product behind it, where there is one.
             *
             * ag-lead follow-up (2026-08-10): the banner's own flag is not the
             * whole answer here. A banner with link_type=product pointing at a
             * product the company has since deactivated would still render in
             * the carousel and, now that ProductController::show answers 404
             * to an Agent, tapping it would dead-end. Advertising something
             * that cannot be opened is a worse outcome than the leak this task
             * started from.
             *
             * `whereNull('product_id')` keeps url/internal banners (TASK-073's
             * other two link types), which have no product to be inactive.
             */
            $query->where(fn ($q) => $q->whereNull('product_id')
                ->orWhereHas('product', fn ($p) => $p->where('is_active', true)));
        } elseif ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // TASK-072 — optional server-side filter by placement (Agent
        // Portal currently fetches all-active-once and groups client-side,
        // but Admin's Banners tab list may want to filter by spot too).
        if ($request->filled('placement')) {
            $query->where('placement', $request->string('placement'));
        }

        return StorefrontBannerResource::collection($query->get());
    }

    public function store(StoreStorefrontBannerRequest $request, StorefrontBannerService $service): StorefrontBannerResource
    {
        $banner = $service->create($request->validated(), $request->user(), $request->file('image'));

        return new StorefrontBannerResource($banner->load('product.media'));
    }

    public function show(StorefrontBanner $storefrontBanner): StorefrontBannerResource
    {
        return new StorefrontBannerResource($storefrontBanner->load('product.media'));
    }

    public function update(UpdateStorefrontBannerRequest $request, StorefrontBanner $storefrontBanner, StorefrontBannerService $service): StorefrontBannerResource
    {
        $banner = $service->update($storefrontBanner, $request->validated(), $request->file('image'));

        return new StorefrontBannerResource($banner->load('product.media'));
    }

    public function destroy(StorefrontBanner $storefrontBanner, StorefrontBannerService $service): Response
    {
        $service->delete($storefrontBanner);

        return response()->noContent();
    }
}
