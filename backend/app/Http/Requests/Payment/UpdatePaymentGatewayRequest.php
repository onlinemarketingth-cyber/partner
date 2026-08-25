<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Credentials arrive as a free-shaped map on purpose.
 *
 * Which keys are valid is the DRIVER's declaration
 * (PaymentGateway::credentialFields()), not a list repeated here — a second
 * copy would drift the first time a provider changed its fields, and the
 * symptom would be a field the form collects and nothing validates.
 * CompanyPaymentGatewayService checks required-ness against that declaration
 * and the provider itself checks the values.
 *
 * `authorize` returns true: the Ability gate lives in the controller, where
 * it is applied to every method including the read.
 */
class UpdatePaymentGatewayRequest extends FormRequest
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
            'credentials' => ['present', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:255'],
            // present, not required: `false` is a real value and `required`
            // rejects it.
            'is_live' => ['present', 'boolean'],
        ];
    }
}
