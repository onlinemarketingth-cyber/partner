<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRecommendationPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product_recommendation_pin'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $pin = $this->route('product_recommendation_pin');
        $companyId = $this->user()->isSuperAdmin()
            ? ($this->input('company_id') ?? $pin?->company_id)
            : $this->user()->company_id;

        return [
            'product_id' => [
                'sometimes',
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
                Rule::unique('product_recommendation_pins', 'product_id')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($pin?->id),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
