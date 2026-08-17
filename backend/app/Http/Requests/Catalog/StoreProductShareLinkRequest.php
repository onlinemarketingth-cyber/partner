<?php

namespace App\Http\Requests\Catalog;

use App\Models\ProductShareLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// TASK-056 Sprint P1 — mirrors StoreAffiliateLinkRequest: an Agent always
// mints a link for themselves (agent_id forced to self in
// ProductShareLinkService); only Company Admin/Super Admin may mint on
// behalf of a different agent.
class StoreProductShareLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ProductShareLink::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agent_id' => [
                Rule::prohibitedIf(fn () => $this->user()->isAgent()),
                Rule::requiredIf(fn () => ! $this->user()->isAgent()),
                'integer',
                Rule::exists('users', 'id')->where('company_id', $this->user()->company_id)->where('role', 'agent'),
            ],
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('company_id', $this->user()->company_id),
            ],
        ];
    }
}
