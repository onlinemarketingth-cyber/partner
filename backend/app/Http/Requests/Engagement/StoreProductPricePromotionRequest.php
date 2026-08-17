<?php

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductPricePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\ProductPricePromotion::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [
                Rule::requiredIf(fn () => $this->user()->isSuperAdmin()),
                Rule::prohibitedIf(fn () => ! $this->user()->isSuperAdmin()),
                'integer',
                Rule::exists('companies', 'id'),
            ],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'discounted_price_satang' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'ended'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
