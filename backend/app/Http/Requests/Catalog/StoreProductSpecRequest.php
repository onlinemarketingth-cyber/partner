<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

// ADR-007 — admin-editable key-value spec (BR-7). Same authorization
// shape as StoreProductMediaRequest.
class StoreProductSpecRequest extends FormRequest
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
        return [
            'spec_group' => ['nullable', 'string', 'max:255'],
            'spec_key' => ['required', 'string', 'max:255'],
            'spec_value' => ['required', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
