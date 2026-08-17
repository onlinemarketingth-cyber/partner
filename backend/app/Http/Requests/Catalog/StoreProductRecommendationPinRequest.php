<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRecommendationPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\ProductRecommendationPin::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->user()->isSuperAdmin()
            ? $this->integer('company_id')
            : $this->user()->company_id;

        return [
            'company_id' => [
                Rule::requiredIf(fn () => $this->user()->isSuperAdmin()),
                Rule::prohibitedIf(fn () => ! $this->user()->isSuperAdmin()),
                'integer',
                Rule::exists('companies', 'id'),
            ],
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
                // DB-level unique(company_id, product_id) is the real
                // guard; this mirrors it at the validation layer so a
                // duplicate pin comes back as a normal 422 rather than a
                // raw 500 from the DB constraint.
                Rule::unique('product_recommendation_pins', 'product_id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
