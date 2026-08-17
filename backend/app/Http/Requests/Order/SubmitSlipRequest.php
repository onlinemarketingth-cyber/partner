<?php

namespace App\Http\Requests\Order;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

// ADR-017 (TASK-054) — the PUBLIC slip upload (POST /pay/{token}/slip).
// Unauthenticated + token-gated (see routes/api.php), so there's no user
// to authorize against — the token IS the authorization. This request only
// validates the uploaded image itself; the same image allow-list/size as
// announcement/client-document images.
//
// ADR-033 (TASK-189) §2.5/D1 — extended for the "one door" shipping
// capture: shipping_recipient_name/phone/address are REQUIRED only when
// $order->product->requires_shipping, otherwise absent/ignored. The order
// is resolved here (by the same public_token the Controller resolves)
// purely to read that one flag — no PDPA/agent/commission data is read or
// exposed.
class SubmitSlipRequest extends FormRequest
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
        $requiresShipping = $this->resolveOrder()?->product?->requires_shipping === true;
        $shippingRule = $requiresShipping ? 'required' : 'nullable';

        return [
            'slip' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], // 5MB payment slip image
            'shipping_recipient_name' => [$shippingRule, 'string', 'max:255'],
            'shipping_phone' => [$shippingRule, 'string', 'max:50'],
            'shipping_address' => [$shippingRule, 'string', 'max:2000'],
        ];
    }

    private function resolveOrder(): ?Order
    {
        $token = $this->route('token');

        if (! is_string($token)) {
            return null;
        }

        return Order::withoutGlobalScopes()->with('product')->where('public_token', $token)->first();
    }
}
