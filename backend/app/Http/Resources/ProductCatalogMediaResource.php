<?php

namespace App\Http\Resources;

use App\Enums\MediaSourceType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

// ADR-036 — mirrors ProductMediaResource's shape exactly (see that class
// for the thumbnail/stream route + "never expose the raw private-disk
// path" reasoning, Section 5 rule 6); the only difference is the FK is
// catalog_item_id, not product_id, and there is no company_id.
//
// THE ROUTE NAMES BELOW DO NOT EXIST YET.
//
// This Resource was written ahead of the catalog media controller
// (TASK-212 scope was Models/Policies/Services/Resources only) on the
// stated assumption that it "is not reachable until those routes exist".
// TASK-220 found that assumption is false: ProductCatalogItemResource:31
// embeds this collection and ProductCatalogItemController::show() eager-
// loads `media`, so a single product_catalog_media row with
// source_type=upload or a thumbnail_path turns route() into a
// RouteNotFoundException and takes the WHOLE catalog-item endpoint down
// with a 500 — not a broken image, a dead page.
//
// Nothing creates those rows through the API today (no controller, no
// service), so it has never fired. That makes it a landmine rather than a
// bug report, and it is defused rather than removed: when TASK-213's
// routes land, the URLs start working with no further change here.
//
// Route::has() rather than try/catch: a missing route is a deployment
// fact to read once, not an exception to swallow per field.
class ProductCatalogMediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'catalog_item_id' => $this->catalog_item_id,
            'media_type' => $this->media_type?->value,
            'source_type' => $this->source_type?->value,
            'purpose' => $this->purpose?->value,
            'stream_url' => $this->source_type === MediaSourceType::Upload && $this->file_path && Route::has('product-catalog-media.stream')
                ? route('product-catalog-media.stream', $this->id)
                : null,
            'thumbnail_url' => $this->thumbnail_path && Route::has('product-catalog-media.thumbnail')
                ? route('product-catalog-media.thumbnail', $this->id)
                : null,
            'embed_url' => $this->embed_url,
            'is_primary' => $this->is_primary,
            'sort_order' => $this->sort_order,
            'processing_status' => $this->processing_status?->value,
            'created_at' => $this->created_at,
        ];
    }
}
