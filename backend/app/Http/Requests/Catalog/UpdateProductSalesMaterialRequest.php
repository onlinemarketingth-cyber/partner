<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

// Human-requested 2026-07-20: only material_group is editable post-upload
// (see ProductSalesMaterialService::updateGroup's own comment for why).
class UpdateProductSalesMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $salesMaterial = $this->route('salesMaterial');

        return $salesMaterial !== null && $this->user()->can('update', $salesMaterial->product);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'material_group' => ['nullable', 'string', 'max:255'],
        ];
    }
}
