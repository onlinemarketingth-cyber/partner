<?php

namespace App\Http\Requests\Commission;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The transfer reference is OPTIONAL on purpose: not every transfer produces
 * one worth recording, and a required field here would only invite made-up
 * values that look like evidence and are not.
 */
class MarkWithdrawalTransferredRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'transfer_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
