<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductCategoryResource extends JsonResource
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
            // TASK-068 / ADR-020 row 3 — Icon.vue icon-name string, null = none chosen.
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            // ADR-026 §3.3 (TASK-132) — category-level journey, null = inherit.
            'pipeline_template_id' => $this->pipeline_template_id,
            // TASK-202 — see BrandResource; only present when counted.
            'products_count' => $this->whenCounted('products'),
            // TASK-245 — see BrandResource::permissions for why this is asked
            // rather than re-derived. Same situation exactly: a category name
            // card can hold this company's row and the platform's.
            'permissions' => [
                'update' => (bool) $request->user()?->can('update', $this->resource),
                'delete' => (bool) $request->user()?->can('delete', $this->resource),
                // 2026-09-09 — the bin tab needs this per row: a Company
                // Admin sees a platform row they cannot bring back, and a
                // button that 403s is worse than no button.
                'restore' => (bool) $request->user()?->can('restore', $this->resource),
            ],
            'deleted_at' => $this->deleted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
