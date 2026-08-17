<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductSalesMaterialRequest;
use App\Http\Requests\Catalog\UpdateProductSalesMaterialRequest;
use App\Http\Resources\ProductSalesMaterialResource;
use App\Models\Product;
use App\Models\ProductSalesMaterial;
use App\Services\Catalog\ProductSalesMaterialService;
use App\Support\Media\RangeFileResponder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

// No dedicated Policy class — reuses ProductPolicy, since "who can see
// this product" / "who can manage this product" is exactly the
// visibility this feature needs: index/download check ProductPolicy::view
// (any same-company user, including Agents — they need to hand these
// materials to clients), store/destroy check ProductPolicy::update
// (Company Admin own company / Super Admin only, per the human's
// explicit choice — see task discussion). Files are never a public URL
// (Section 5 rule 6 pattern) — download() is the only way to read
// content, mirrors ClientDocumentController exactly.
class ProductSalesMaterialController extends Controller
{
    public function index(Product $product): AnonymousResourceCollection
    {
        $this->authorize('view', $product);

        return ProductSalesMaterialResource::collection($product->salesMaterials()->latest()->get());
    }

    public function store(StoreProductSalesMaterialRequest $request, Product $product, ProductSalesMaterialService $service): ProductSalesMaterialResource
    {
        $material = $service->store($product, $request->validated(), $request->file('file'), $request->user());

        return new ProductSalesMaterialResource($material);
    }

    /** PATCH /sales-materials/{salesMaterial} — material_group only (human-requested 2026-07-20). */
    public function update(UpdateProductSalesMaterialRequest $request, ProductSalesMaterial $salesMaterial, ProductSalesMaterialService $service): ProductSalesMaterialResource
    {
        $material = $service->updateGroup($salesMaterial, $request->validated('material_group'));

        return new ProductSalesMaterialResource($material);
    }

    public function download(ProductSalesMaterial $salesMaterial, ProductSalesMaterialService $service): mixed
    {
        $this->authorize('view', $salesMaterial->product);
        // ADR-007 — an embedded material (source_type=embed) has no file
        // of ours to stream; the client should render/open embed_url
        // itself instead of calling this route.
        abort_if($salesMaterial->file_path === null, 404);

        return Storage::disk($service->disk())->download($salesMaterial->file_path, $salesMaterial->original_filename);
    }

    /**
     * GET /sales-materials/{salesMaterial}/stream — INLINE view (never a
     * public URL, Section 5 rule 6), as opposed to download()'s forced
     * save-as. Human-requested 2026-07-20: the redesigned Sales Materials
     * grid needs live thumbnails (PdfThumbnail.vue for PDFs,
     * AuthenticatedMedia for images/video) and click-to-preview via
     * MediaPreviewModal — the same pattern product_media/
     * product_spec_attachments already have, sales materials never did
     * until now (this model was originally download-only, like
     * ClientDocument).
     *
     * TASK-143 / ADR-028 §2.5 — now byte-range capable. A sales material
     * may be a compressed VIDEO (ADR-007), and without Range the browser
     * had to buffer the whole file before it could seek. Authorization is
     * unchanged and still runs before any bytes.
     */
    public function stream(Request $request, ProductSalesMaterial $salesMaterial, ProductSalesMaterialService $service): mixed
    {
        $this->authorize('view', $salesMaterial->product);
        abort_if($salesMaterial->file_path === null, 404);

        return RangeFileResponder::respond(
            Storage::disk($service->disk()),
            $salesMaterial->file_path,
            $request,
            RangeFileResponder::DISPOSITION_INLINE,
            $salesMaterial->original_filename,
        );
    }

    public function destroy(ProductSalesMaterial $salesMaterial, ProductSalesMaterialService $service): Response
    {
        $this->authorize('update', $salesMaterial->product);

        $service->delete($salesMaterial);

        return response()->noContent();
    }
}
