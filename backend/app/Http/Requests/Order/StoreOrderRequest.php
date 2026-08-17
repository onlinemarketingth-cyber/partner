<?php

namespace App\Http\Requests\Order;

use App\Enums\PaymentMethod;
use App\Models\Referral;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

// ADR-017 (TASK-054) — create an order for a referral. The order's
// company/client/agent/product all derive from the referral in
// OrderService (§5 — never trusted from the client); only referral_id and
// the chosen payment_method are accepted here.
class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Order::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'referral_id' => [
                'required',
                'integer',
                // Same-company only (TenantScope covers reads, but a Form
                // Request runs before the query — pin it explicitly here).
                Rule::exists('referrals', 'id')->where('company_id', $this->user()->company_id),
            ],
            'payment_method' => ['required', new Enum(PaymentMethod::class)],
        ];
    }

    /**
     * §5 rule 4: an Agent may only create an order for a referral THEY own
     * (agent_id = self) — the company-scoped exists() rule above only proves
     * "same company", not "same agent". Rejected at validation (422), same
     * shape as StoreReferralRequest's client-ownership check.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->user()->isAgent() || ! $this->filled('referral_id')) {
                return;
            }

            $referral = Referral::withoutGlobalScopes()->find($this->input('referral_id'));

            if ($referral && $referral->agent_id !== $this->user()->id) {
                $validator->errors()->add('referral_id', 'คุณสร้างคำสั่งซื้อได้เฉพาะรายการอ้างอิงของคุณเองเท่านั้น');
            }
        });
    }
}
