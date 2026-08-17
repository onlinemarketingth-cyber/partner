<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

// ADR-007 — only is_primary/sort_order are editable after creation
// (changing the actual file/embed_url means deleting and re-adding —
// same "no free-form edit of an uploaded artifact" spirit as
// ProductSalesMaterial, which also has no update route).
class UpdateProductMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $productMedia = $this->route('productMedia');

        return $productMedia !== null && $this->user()->can('update', $productMedia->product);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_primary' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
