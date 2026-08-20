<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// ADR-036 — deliberately the SAME shape as BrandResource (company_id
// always null here, since catalog_brands has no such column) so the
// frontend's existing `Brand { id, name, is_active }` TypeScript
// interface keeps working unchanged when a product's brand comes from
// the shared catalog instead of its own company row (see ProductResource
// 'brand' key).
class CatalogBrandResource extends JsonResource
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
            'logo_path' => $this->logo_path,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
