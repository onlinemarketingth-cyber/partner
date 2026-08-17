<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

// TASK-068 / ADR-020 row 2. `product` is a nested minimal payload
// (id/name/thumbnail_url) — enough for the frontend to render the banner
// and link straight to the product without a second request, per
// TASK-068's own spec. image_url is a direct public-disk URL, same
// convention as AnnouncementResource.image_url.
//
// TASK-073 (2026-08-02) — a banner's click target is now one of 3 types
// (link_type: product/url/internal). `product` is only populated when
// link_type === 'product' (product_id is nullable now — see
// StorefrontBanner model docblock); external_url/internal_path expose the
// other 2 target kinds directly.
class StorefrontBannerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'link_type' => $this->link_type?->value,
            'external_url' => $this->external_url,
            'internal_path' => $this->internal_path,
            'product' => $this->when($this->relationLoaded('product') && $this->product !== null, function () {
                // TASK-097 — same cover-first resolution order as
                // ProductResource::thumbnail_url; kept in step with it
                // deliberately, since a banner and a card showing
                // different images for the same product would read as a
                // bug to the agent looking at both on one screen.
                $primaryMedia = null;
                if ($this->product->relationLoaded('media')) {
                    $covers = $this->product->media->where('purpose', \App\Enums\ProductMediaPurpose::Cover);

                    $primaryMedia = $covers->firstWhere('is_primary', true)
                        ?? $covers->first()
                        ?? $this->product->media->firstWhere('is_primary', true)
                        ?? $this->product->media->first();
                }

                // Same fallback as ProductResource::thumbnail_url (2026-08-01
                // fix) — thumbnail_path is only ever set for video media, so
                // a banner linking to an image-only product must stream the
                // image itself rather than showing nothing.
                $thumbnailUrl = null;
                if ($primaryMedia) {
                    if ($primaryMedia->thumbnail_path) {
                        $thumbnailUrl = route('product-media.thumbnail', $primaryMedia->id);
                    } elseif ($primaryMedia->media_type === \App\Enums\ProductMediaType::Image) {
                        $thumbnailUrl = route('product-media.stream', $primaryMedia->id);
                    }
                }

                return [
                    'id' => $this->product->id,
                    'name' => $this->product->name,
                    'thumbnail_url' => $thumbnailUrl,
                ];
            }),
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'title' => $this->title,
            'placement' => $this->placement?->value,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
