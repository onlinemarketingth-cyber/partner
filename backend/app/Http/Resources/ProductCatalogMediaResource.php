<?php

namespace App\Http\Resources;

use App\Enums\MediaSourceType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// ADR-036 — mirrors ProductMediaResource's shape exactly (see that class
// for the thumbnail/stream route + "never expose the raw private-disk
// path" reasoning, Section 5 rule 6); the only difference is the FK is
// catalog_item_id, not product_id, and there is no company_id.
//
// The 'product-catalog-media.thumbnail'/'.stream' route names are wired
// in TASK-213 alongside the catalog media controller — this Resource is
// written ahead of that controller (TASK-212 scope is Models/Policies/
// Services/Resources only) but is not reachable until those routes and
// their authorization exist, so referencing the names now is safe.
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
            'stream_url' => $this->source_type === MediaSourceType::Upload && $this->file_path
                ? route('product-catalog-media.stream', $this->id)
                : null,
            'thumbnail_url' => $this->thumbnail_path ? route('product-catalog-media.thumbnail', $this->id) : null,
            'embed_url' => $this->embed_url,
            'is_primary' => $this->is_primary,
            'sort_order' => $this->sort_order,
            'processing_status' => $this->processing_status?->value,
            'created_at' => $this->created_at,
        ];
    }
}
