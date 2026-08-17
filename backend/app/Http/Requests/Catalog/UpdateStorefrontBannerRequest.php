<?php

namespace App\Http\Requests\Catalog;

use App\Enums\StorefrontBannerLinkType;
use App\Enums\StorefrontBannerPlacement;
use App\Support\StorefrontBannerInternalPaths;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStorefrontBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('storefront_banner'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $banner = $this->route('storefront_banner');
        $companyId = $this->user()->isSuperAdmin()
            ? ($this->input('company_id') ?? $banner?->company_id)
            : $this->user()->company_id;

        // TASK-073 — only enforce the "exactly one of product_id /
        // external_url / internal_path" required/prohibited pairing when
        // the caller is actually changing link_type in this request. A
        // partial update that just touches e.g. `title` or `sort_order`
        // must not be forced to resend the link target.
        $linkType = $this->input('link_type');
        $linkTypeProvided = $this->has('link_type');

        return [
            'link_type' => ['sometimes', Rule::enum(StorefrontBannerLinkType::class)],
            'product_id' => [
                $linkTypeProvided ? Rule::requiredIf(fn () => $linkType === 'product') : 'sometimes',
                $linkTypeProvided ? Rule::prohibitedIf(fn () => $linkType !== 'product') : 'nullable',
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'external_url' => [
                $linkTypeProvided ? Rule::requiredIf(fn () => $linkType === 'url') : 'sometimes',
                $linkTypeProvided ? Rule::prohibitedIf(fn () => $linkType !== 'url') : 'nullable',
                'url',
                'max:2048',
            ],
            'internal_path' => [
                $linkTypeProvided ? Rule::requiredIf(fn () => $linkType === 'internal') : 'sometimes',
                $linkTypeProvided ? Rule::prohibitedIf(fn () => $linkType !== 'internal') : 'nullable',
                Rule::in(StorefrontBannerInternalPaths::ALLOWED),
            ],
            // Image is optional on update — a banner already has one from
            // creation (image_path is not nullable), sending a new file
            // replaces it; omitting it leaves the current image untouched.
            'image' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'placement' => ['sometimes', Rule::enum(StorefrontBannerPlacement::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
