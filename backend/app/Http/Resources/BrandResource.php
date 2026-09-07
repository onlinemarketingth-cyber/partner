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
            /*
             * TASK-245 / ADR-040 — what THIS viewer may do to THIS row,
             * answered by the Policy that will actually be consulted when they
             * try, not re-derived on screen.
             *
             * It became necessary the moment a brand could be PLATFORM-owned:
             * the catalogue groups rows by name, so one card on screen can hold
             * a company's own brand AND the platform's, and "may I rename this
             * card" now has two different answers inside one card. A screen
             * that guesses offers a button and then gets a 403 — or, worse,
             * hides one from somebody who was allowed.
             *
             * Always present rather than opt-in: BrandPolicy reads only loaded
             * attributes, so this costs two in-memory calls per row and no
             * queries, and a flag the caller can forget is exactly how the
             * wrong button comes back.
             */
            'permissions' => [
                'update' => (bool) $request->user()?->can('update', $this->resource),
                'delete' => (bool) $request->user()?->can('delete', $this->resource),
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
