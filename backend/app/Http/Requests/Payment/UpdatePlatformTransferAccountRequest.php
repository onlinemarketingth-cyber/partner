<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-050 — the platform's own bank account / PromptPay. Same field limits
 * as a company's (UpdateCompanyRequest's payment_* rules).
 */
class UpdatePlatformTransferAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'promptpay_id' => ['present', 'nullable', 'string', 'max:255'],
            'bank_name' => ['present', 'nullable', 'string', 'max:255'],
            'bank_account_number' => ['present', 'nullable', 'string', 'max:255'],
            'bank_account_name' => ['present', 'nullable', 'string', 'max:255'],
        ];
    }
}
