<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-027 / TASK-139 — the PUBLIC card charge (POST /pay/{token}/charge).
 *
 * Unauthenticated and token-gated exactly like its slip sibling: the
 * unguessable public_token IS the authorization, and there is no user to
 * check anything against.
 *
 * ── ONE FIELD, AND THAT IS THE POINT ──
 *
 * A one-time token the provider's JS made in the customer's browser. No card
 * number, no expiry, no CVV — not "validated and discarded", ABSENT. Card
 * data never touches this server, which is why this application is out of
 * scope for most of PCI-DSS rather than merely compliant with it.
 *
 * Notably ALSO absent: the amount. It is read from the order, server-side. A
 * client-supplied amount is a client-supplied price.
 *
 * Shipping fields are absent too, and that is a real gap rather than an
 * oversight: the slip flow captures them (ADR-033 §2.5) and the card flow
 * will need to as well for a product that ships. It is not here because the
 * card form's shipping capture is a pay-page design decision, and guessing
 * at its shape now would put a second, differently-validated copy of those
 * three fields in the codebase. Flagged in /docs rather than half-built.
 */
class ChargeOrderRequest extends FormRequest
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
            // Bounded on purpose. Provider tokens are short opaque strings;
            // anything long arriving here is somebody probing, and an
            // unbounded string is what gets forwarded to a third party's API
            // in a request we pay for.
            'payment_token' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_token.required' => 'ไม่พบข้อมูลบัตร กรุณากรอกใหม่อีกครั้ง',
        ];
    }
}
