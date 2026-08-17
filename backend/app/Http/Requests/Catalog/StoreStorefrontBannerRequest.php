<?php

namespace App\Http\Requests\Catalog;

use App\Enums\StorefrontBannerLinkType;
use App\Enums\StorefrontBannerPlacement;
use App\Support\StorefrontBannerInternalPaths;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStorefrontBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\StorefrontBanner::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Same "company_id required for Super Admin only, product_id must
        // belong to that resolved company" shape as
        // StoreProductPricePromotionRequest — ADR-020 decision #2: a
        // banner's product_id must be in the SAME company as the banner.
        $companyId = $this->user()->isSuperAdmin()
            ? $this->integer('company_id')
            : $this->user()->company_id;

        // TASK-073 — link_type defaults to 'product' when omitted, so
        // existing/legacy clients that only ever sent product_id keep
        // working unchanged.
        $linkType = $this->input('link_type', 'product');

        return [
            'company_id' => [
                Rule::requiredIf(fn () => $this->user()->isSuperAdmin()),
                Rule::prohibitedIf(fn () => ! $this->user()->isSuperAdmin()),
                'integer',
                Rule::exists('companies', 'id'),
            ],
            'link_type' => ['sometimes', Rule::enum(StorefrontBannerLinkType::class)],
            'product_id' => [
                Rule::requiredIf(fn () => $linkType === 'product'),
                Rule::prohibitedIf(fn () => $linkType !== 'product'),
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'external_url' => [
                Rule::requiredIf(fn () => $linkType === 'url'),
                Rule::prohibitedIf(fn () => $linkType !== 'url'),
                'url',
                'max:2048',
            ],
            'internal_path' => [
                Rule::requiredIf(fn () => $linkType === 'internal'),
                Rule::prohibitedIf(fn () => $linkType !== 'internal'),
                Rule::in(StorefrontBannerInternalPaths::ALLOWED),
            ],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'title' => ['nullable', 'string', 'max:255'],
            // TASK-072 — human-confirmed via AskUserQuestion (2026-08-02):
            // 3 fixed placement spots on ProductBrowseView.vue. `sometimes`
            // so an omitted value falls through to the column default
            // ('top') set in the migration.
            'placement' => ['sometimes', Rule::enum(StorefrontBannerPlacement::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
