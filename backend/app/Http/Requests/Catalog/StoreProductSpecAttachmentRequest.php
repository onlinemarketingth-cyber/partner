<?php

namespace App\Http\Requests\Catalog;

use App\Enums\MediaSourceType;
use App\Enums\ProductSpecAttachmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-008 — Company Admin (own company)/Super Admin manage the catalog,
// same as StoreProductMediaRequest (reuses ProductPolicy::update, no
// dedicated Policy class for this model either). Unlike ProductMedia,
// source_type is always required regardless of media_type — both image
// and pdf may be either uploaded or embedded here.
class StoreProductSpecAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product !== null && $this->user()->can('update', $product);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isPdf = $this->input('media_type') === ProductSpecAttachmentType::Pdf->value;
        $isEmbed = $this->input('source_type') === MediaSourceType::Embed->value;

        return [
            'media_type' => ['required', Rule::enum(ProductSpecAttachmentType::class)],
            'source_type' => ['required', Rule::enum(MediaSourceType::class)],
            'file' => [
                Rule::requiredIf(fn () => ! $isEmbed),
                Rule::prohibitedIf(fn () => $isEmbed),
                'file',
                $isPdf ? 'mimes:'.implode(',', config('media.pdf.allowed_mimes')) : 'mimes:jpg,jpeg,png,webp',
                'max:'.($isPdf ? config('media.pdf.max_upload_mb') * 1024 : 15360),
            ],
            'embed_url' => [
                Rule::requiredIf(fn () => $isEmbed),
                Rule::prohibitedIf(fn () => ! $isEmbed),
                'url', 'max:2048',
            ],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
