<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductSpecAttachmentRequest;
use App\Http\Requests\Catalog\UpdateProductSpecAttachmentRequest;
use App\Http\Resources\ProductSpecAttachmentResource;
use App\Models\Product;
use App\Models\ProductSpecAttachment;
use App\Services\Catalog\ProductSpecAttachmentService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

// ADR-008 — no dedicated Policy, reuses ProductPolicy exactly like
// ProductMediaController (see that controller's own comment for why):
// index/stream/thumbnail check ProductPolicy::view (any same-company
// user — Agents need to see the gallery, not just admins), store/update/
// destroy check ProductPolicy::update (Company Admin own company /
// Super Admin only).
class ProductSpecAttachmentController extends Controller
{
    public function index(Product $product): AnonymousResourceCollection
    {
        $this->authorize('view', $product);

        return ProductSpecAttachmentResource::collection($product->specAttachments);
    }

    public function store(StoreProductSpecAttachmentRequest $request, Product $product, ProductSpecAttachmentService $service): ProductSpecAttachmentResource
    {
        $attachment = $service->store($product, $request->validated(), $request->file('file'), $request->user());

        return new ProductSpecAttachmentResource($attachment);
    }

    public function update(UpdateProductSpecAttachmentRequest $request, ProductSpecAttachment $productSpecAttachment, ProductSpecAttachmentService $service): ProductSpecAttachmentResource
    {
        $attachment = $service->update($productSpecAttachment, $request->validated());

        return new ProductSpecAttachmentResource($attachment);
    }

    public function destroy(ProductSpecAttachment $productSpecAttachment, ProductSpecAttachmentService $service): Response
    {
        $this->authorize('update', $productSpecAttachment->product);

        $service->delete($productSpecAttachment);

        return response()->noContent();
    }

    /** GET /product-spec-attachments/{productSpecAttachment}/stream — never a public URL (Section 5 rule 6). */
    public function stream(ProductSpecAttachment $productSpecAttachment, ProductSpecAttachmentService $service): mixed
    {
        $this->authorize('view', $productSpecAttachment->product);

        return Storage::disk($service->disk())->response($productSpecAttachment->file_path);
    }

    public function thumbnail(ProductSpecAttachment $productSpecAttachment, ProductSpecAttachmentService $service): mixed
    {
        $this->authorize('view', $productSpecAttachment->product);
        abort_unless($productSpecAttachment->thumbnail_path, 404);

        return Storage::disk($service->disk())->response($productSpecAttachment->thumbnail_path);
    }

    /**
     * GET /product-spec-attachments/{productSpecAttachment}/download —
     * forces a browser save-as, same reasoning as
     * ProductMediaController::download() (human-requested 2026-07-19):
     * download icon on every image/PDF tile, excluding embed items.
     */
    public function download(ProductSpecAttachment $productSpecAttachment, ProductSpecAttachmentService $service): mixed
    {
        $this->authorize('view', $productSpecAttachment->product);
        abort_if($productSpecAttachment->source_type === 'embed', 404);

        return Storage::disk($service->disk())->download($productSpecAttachment->file_path, basename($productSpecAttachment->file_path));
    }
}
