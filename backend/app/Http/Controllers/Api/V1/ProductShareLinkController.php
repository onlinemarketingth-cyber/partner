<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductShareLinkRequest;
use App\Http\Resources\ProductShareLinkResource;
use App\Models\ProductShareLink;
use App\Services\Catalog\ProductShareLinkService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// TASK-056 Sprint P1 — mirrors AffiliateLinkController exactly (index
// narrows to the Agent's own links; no update() — a link's
// agent/product/token are immutable once minted, revoking is destroy()).
class ProductShareLinkController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(ProductShareLink::class, 'product_share_link');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ProductShareLink::with('product');

        if ($request->user()->isAgent()) {
            $query->where('agent_id', $request->user()->id);
        }

        return ProductShareLinkResource::collection($query->latest()->paginate());
    }

    public function store(StoreProductShareLinkRequest $request, ProductShareLinkService $service): ProductShareLinkResource
    {
        $link = $service->create($request->validated(), $request->user());

        return new ProductShareLinkResource($link->load('product'));
    }

    public function show(ProductShareLink $productShareLink): ProductShareLinkResource
    {
        return new ProductShareLinkResource($productShareLink->load('product'));
    }

    public function destroy(ProductShareLink $productShareLink): Response
    {
        $productShareLink->update(['revoked_at' => now()]);

        return response()->noContent();
    }
}
