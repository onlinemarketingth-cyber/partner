<?php

namespace App\Http\Requests\Payment;

use App\Enums\PaymentAccountScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-050 — the platform switch. Authorisation is the controller's
 * (Ability::SettingsPaymentGatewayUpdate); readiness is the service's.
 */
class UpdatePlatformPaymentModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(PaymentAccountScope::class)],
        ];
    }
}
