<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

// ADR-008 — only sort_order is editable after creation (no is_primary
// concept here, unlike ProductMedia — changing the actual file/embed_url
// means deleting and re-adding, same "no free-form edit of an uploaded
// artifact" spirit as ProductMedia/ProductSalesMaterial).
class UpdateProductSpecAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $productSpecAttachment = $this->route('productSpecAttachment');

        return $productSpecAttachment !== null && $this->user()->can('update', $productSpecAttachment->product);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
