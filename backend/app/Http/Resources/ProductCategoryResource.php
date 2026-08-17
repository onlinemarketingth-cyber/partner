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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
