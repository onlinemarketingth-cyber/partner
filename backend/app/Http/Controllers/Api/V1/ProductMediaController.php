<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductMediaPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductMediaRequest;
use App\Http\Requests\Catalog\UpdateProductMediaRequest;
use App\Http\Resources\ProductMediaResource;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Services\Catalog\ProductMediaService;
use App\Support\Media\RangeFileResponder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

// ADR-007 — no dedicated Policy, reuses ProductPolicy exactly like
// ProductSalesMaterialController (see that controller's own comment for
// why): index/stream/thumbnail check ProductPolicy::view (any
// same-company user — Agents need to see the gallery, not just admins),
// store/update/destroy check ProductPolicy::update (Company Admin own
// company / Super Admin only).
class ProductMediaController extends Controller
{
    /**
     * TASK-097 — `?purpose=cover|detail` narrows the response to one of
     * the two galleries. Unfiltered still returns everything (cover
     * first, see Product::media()) so no existing caller changes.
     *
     * Validated against the enum rather than passed through: an
     * unrecognised value must be a 422, not a silently empty gallery
     * that looks like "the admin deleted all the photos".
     */
    public function index(Request $request, Product $product): AnonymousResourceCollection
    {
        $this->authorize('view', $product);

        $purpose = $request->query('purpose');

        if ($purpose !== null) {
            $request->validate(['purpose' => [Rule::enum(ProductMediaPurpose::class)]]);
        }

        $media = $purpose === null
            ? $product->media
            : $product->media()->where('purpose', $purpose)->get();

        return ProductMediaResource::collection($media);
    }

    public function store(StoreProductMediaRequest $request, Product $product, ProductMediaService $service): ProductMediaResource
    {
        $media = $service->store($product, $request->validated(), $request->file('file'), $request->user());

        return new ProductMediaResource($media);
    }

    public function update(UpdateProductMediaRequest $request, ProductMedia $productMedia, ProductMediaService $service): ProductMediaResource
    {
        $media = $service->update($productMedia, $request->validated());

        return new ProductMediaResource($media);
    }

    public function destroy(ProductMedia $productMedia, ProductMediaService $service): Response
    {
        $this->authorize('update', $productMedia->product);

        $service->delete($productMedia);

        return response()->noContent();
    }

    /**
     * GET /product-media/{productMedia}/stream — never a public URL
     * (Section 5 rule 6).
     *
     * TASK-143 / ADR-028 §2.5 — byte-range capable, because product media
     * includes uploaded VIDEO (ADR-007) and a seek without Range means
     * downloading everything before it. Authorization is unchanged and
     * still runs before any bytes are served.
     */
    public function stream(Request $request, ProductMedia $productMedia, ProductMediaService $service): mixed
    {
        $this->authorize('view', $productMedia->product);

        return RangeFileResponder::respond(
            Storage::disk($service->disk()),
            $productMedia->file_path,
            $request,
            RangeFileResponder::DISPOSITION_INLINE,
        );
    }

    public function thumbnail(ProductMedia $productMedia, ProductMediaService $service): mixed
    {
        $this->authorize('view', $productMedia->product);
        abort_unless($productMedia->thumbnail_path, 404);

        return Storage::disk($service->disk())->response($productMedia->thumbnail_path);
    }

    /**
     * GET /product-media/{productMedia}/download — forces a browser
     * save-as (Content-Disposition: attachment) rather than the inline
     * view stream() returns. Human-requested 2026-07-19: a download icon
     * on every image/video tile, excluding embed items (there's no file
     * to download — embed_url IS the content, at an external host).
     * ProductMedia has no original_filename column, so the stored
     * file_path's basename (a UUID + original extension, per
     * ProductMediaService::store()) is used as the downloaded filename.
     */
    public function download(ProductMedia $productMedia, ProductMediaService $service): mixed
    {
        $this->authorize('view', $productMedia->product);
        abort_if($productMedia->source_type === 'embed', 404);

        return Storage::disk($service->disk())->download($productMedia->file_path, basename($productMedia->file_path));
    }
}
