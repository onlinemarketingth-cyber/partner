<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductSpecRequest extends FormRequest
{
    public function authorize(): bool
    {
        $productSpec = $this->route('productSpec');

        return $productSpec !== null && $this->user()->can('update', $productSpec->product);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'spec_group' => ['sometimes', 'nullable', 'string', 'max:255'],
            'spec_key' => ['sometimes', 'required', 'string', 'max:255'],
            'spec_value' => ['sometimes', 'required', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
