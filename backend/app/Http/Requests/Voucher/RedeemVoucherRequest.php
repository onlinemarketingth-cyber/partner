<?php

namespace App\Http\Requests\Voucher;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;

// ADR-033 (TASK-189) §2.1/C4 — POST /api/v1/vouchers/redeem, gated behind
// Ability::VoucherRedeem (CompanyAdmin/SuperAdmin, NOT Agent). `branch` is
// free text (ADR-033 §2.1 — "สาขาไหนก็ได้", not a `branches` FK).
class RedeemVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Ability::VoucherRedeem);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
            'branch' => ['nullable', 'string', 'max:255'],
        ];
    }
}
