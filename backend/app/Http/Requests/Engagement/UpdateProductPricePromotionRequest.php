<?php

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductPricePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product_price_promotion'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'discounted_price_satang' => ['sometimes', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'ended'])],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
