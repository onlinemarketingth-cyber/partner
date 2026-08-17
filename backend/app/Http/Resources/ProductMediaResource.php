<?php

namespace App\Http\Resources;

use App\Enums\MediaSourceType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductMediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'media_type' => $this->media_type?->value,
            'source_type' => $this->source_type?->value,
            // TASK-097 — 'cover' (รูปสินค้า) vs 'detail' (รายละเอียดสินค้า).
            'purpose' => $this->purpose?->value,
            // Never expose the raw private-disk path (Section 5 rule 6)
            // — only a controller-served stream URL. embed_url is
            // already an external link, safe to expose as-is.
            'stream_url' => $this->source_type === MediaSourceType::Upload && $this->file_path
                ? route('product-media.stream', $this->id)
                : null,
            'thumbnail_url' => $this->thumbnail_path ? route('product-media.thumbnail', $this->id) : null,
            'embed_url' => $this->embed_url,
            'is_primary' => $this->is_primary,
            'sort_order' => $this->sort_order,
            'processing_status' => $this->processing_status?->value,
            'created_at' => $this->created_at,
        ];
    }
}
