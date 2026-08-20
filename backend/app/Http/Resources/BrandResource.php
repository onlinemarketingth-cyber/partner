<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class BrandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'logo_path' => $this->logo_path,
            // TASK-205 — direct public-disk URL, same convention as
            // StorefrontBannerResource::image_url / AnnouncementResource.
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'is_active' => $this->is_active,
            // TASK-202 — only present when the query actually counted
            // (BrandController::index does; show/store/update do not), so
            // no other consumer's payload shape changes.
            'products_count' => $this->whenCounted('products'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
