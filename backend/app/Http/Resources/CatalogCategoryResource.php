<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// ADR-036 — mirrors ProductCategoryResource's shape (company_id and
// pipeline_template_id always null here — catalog_categories has neither
// column) so a catalog-linked product's category renders identically to
// a standalone product's on the frontend.
class CatalogCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => null,
            'name' => $this->name,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'pipeline_template_id' => null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
