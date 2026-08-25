<?php

namespace App\Http\Requests\Payment;

use App\Enums\PaymentProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Switch a company to one gateway.
 *
 * One provider, never a list — the human's rule is exactly one active
 * gateway, and a payload that could carry two would be a shape in which the
 * rule has to be re-enforced rather than one in which it cannot be broken.
 */
class ActivatePaymentGatewayRequest extends FormRequest
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
            'provider' => ['required', 'string', Rule::in(array_column(PaymentProvider::cases(), 'value'))],
        ];
    }
}
