<?php

namespace App\Http\Requests\Referral;

use App\Models\AffiliateLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-011/TASK-032 — an Agent always mints a link for themselves
// (agent_id forced to self in AffiliateLinkService, same pattern as
// StoreReferralRequest/StoreClientRequest); only Company Admin/Super
// Admin may mint on behalf of a different agent.
class StoreAffiliateLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AffiliateLink::class);
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
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where('company_id', $this->user()->company_id),
            ],
        ];
    }
}
