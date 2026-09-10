<?php

namespace App\Http\Requests\Order;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 2026-09-10 — POST /pay/{token}/intent, now that a card payer is asked for
 * their shipping address too.
 *
 * ── THE GAP THIS CLOSES ──
 *
 * ADR-033 §2.5/D1 collected the address in the SAME request as the slip —
 * "one door" — which was right when the slip was the only way to pay. It
 * stopped being right the moment the card button appeared beside it: a
 * customer buying a physical product with a card went straight to the gateway
 * and was NEVER asked where to send it. The order came back paid, with no
 * address on it, and nothing anywhere said one was missing.
 *
 * So the door moved. The address is asked for BEFORE the payment method is
 * chosen (which is where every other checkout asks for it), and it is saved by
 * whichever endpoint the customer's chosen method goes through. The slip
 * request keeps its own copy of these rules because it is still a valid door —
 * a customer who transfers never touches this one.
 *
 * Same rules as SubmitSlipRequest, for the same reason: REQUIRED only when the
 * product actually ships. A non-physical product must never be blocked on
 * three fields nobody will ever read.
 */
class StartOnlinePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Unauthenticated and token-gated — the token IS the authorization,
        // same position as its slip sibling.
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
