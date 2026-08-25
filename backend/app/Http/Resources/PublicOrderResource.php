<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Resources\Concerns\ResolvesPublicTheme;
use App\Models\Company;
use App\Models\Order;
use App\Services\Payment\CompanyPaymentGatewayService;
use App\Services\Payment\PaymentGatewayRegistry;
use App\Services\Payment\PromptPayService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// ADR-017 (TASK-054) — the PUBLIC /pay/{token} view, served UNAUTHENTICATED.
// Deliberately exposes ONLY what a paying customer needs: the amount, the
// product name, their own display name, and the company's payment details +
// a PromptPay payload. NO agent, commission, referral, or PDPA data (§6).
//
// ADR-033 (TASK-189) §2.4/§2.5 — extended with `requires_shipping` + the
// current shipping_* values (so the frontend pay page knows whether to
// render the address form and can pre-fill on a re-visit, D3), and a
// `voucher` block once the order is paid (E1 — code/status/quota/expiry
// only, never the redemption audit trail).
class PublicOrderResource extends JsonResource
{
    use ResolvesPublicTheme;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $company = $this->company;

        // PromptPay payload only when the customer chose PromptPay AND the
        // company actually configured a proxy id — otherwise empty string.
        $promptpayPayload = '';
        if ($this->payment_method === PaymentMethod::PromptPay && $company?->payment_promptpay_id) {
            $promptpayPayload = app(PromptPayService::class)->payload(
                $company->payment_promptpay_id,
                $this->amount_satang,
            );
        }

        return [
            'order_number' => $this->order_number,
            // BR-3 — integer satang on the wire; baht is display convenience.
            'amount_satang' => $this->amount_satang,
            'amount_baht' => round($this->amount_satang / 100, 2),
            'payment_method' => $this->payment_method->value,
            'payment_method_label' => $this->payment_method->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'product_name' => $this->product?->name,
            // Display-only customer name — no phone/email/national id/health data.
            'client_name' => $this->client?->name,
            // ADR-027 / TASK-139 — which gateway this order is being paid
            // through, and what the page must render for it.
            'gateway' => $this->gatewayBlock($company),
            'company_payment' => [
                'bank_name' => $company?->payment_bank_name,
                'bank_account_number' => $company?->payment_bank_account_number,
                'bank_account_name' => $company?->payment_bank_account_name,
                'promptpay_id' => $company?->payment_promptpay_id,
            ],
            'promptpay_payload' => $promptpayPayload,
            // ADR-033 (TASK-189) §2.5/D3 — whether the pay page must render
            // the shipping-address form, and the current values so a
            // customer who already filled it in sees them on a re-visit.
            'requires_shipping' => (bool) $this->product?->requires_shipping,
            'shipping_recipient_name' => $this->shipping_recipient_name,
            'shipping_phone' => $this->shipping_phone,
            'shipping_address' => $this->shipping_address,
            // ADR-033 §2.2/§2.4 — only once paid, and only when a voucher
            // was actually issued (older/legacy paid orders predate this
            // feature and may have none).
            'voucher' => $this->when(
                $this->status === OrderStatus::Paid && $this->voucher !== null,
                fn () => [
                    'code' => $this->voucher->code,
                    'status' => $this->voucher->status()->value,
                    'status_label' => $this->voucher->status()->label(),
                    'used_count' => $this->voucher->used_count,
                    'usage_quota' => $this->voucher->usage_quota,
                    'quota_remaining' => $this->voucher->quotaRemaining(),
                    'expires_at' => $this->voucher->expires_at?->toIso8601String(),
                ],
            ),
            // TASK-159 §3 — the theme of the company that owns this order,
            // resolved from the order's public_token alone. This is the one
            // page a PAYING customer sees; it carried no slug, so before
            // this it rendered on platform defaults.
            'theme' => $this->publicTheme($company),
        ];
    }

    /**
     * What the pay page must render, and whether it may still take money.
     *
     * ── `payment_received` IS THE MOST IMPORTANT KEY HERE ──
     *
     * It is true the instant a gateway charge id is claimed, which happens
     * BEFORE the order is confirmed and stays true even in the rare case
     * where confirmation itself fails. The page reads this — not `status` —
     * to decide whether to offer a card form, because an order that is
     * `pending` with money already taken must never invite a second payment.
     *
     * ── THE MANUAL FLOW GETS NO INTENT, ON PURPOSE ──
     *
     * Everything a slip-and-transfer customer needs is already in
     * `company_payment` and `promptpay_payload` above, where ADR-017 put it
     * and where the live pay page reads it today. Emitting a second
     * description of the same thing would create two places that must agree
     * about how a customer pays, and one of them would eventually be wrong.
     *
     * ── null intent MEANS "CANNOT PAY BY CARD RIGHT NOW" ──
     *
     * Rendering a card form backed by a gateway the company has switched off
     * would collect card details for a charge certain to be refused. Better
     * to say so.
     *
     * @return array<string, mixed>
     */
    private function gatewayBlock(?Company $company): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $provider = $order->payment_provider;

        $block = [
            'provider' => $provider?->value,
            // A charge made with a test key is not revenue, and the customer
            // is entitled to know which one they are about to make.
            'mode' => $order->gateway_mode,
            'payment_received' => $order->hasGatewayPayment(),
            'intent' => null,
        ];

        if ($company === null || $provider === null || $provider->requiresHumanVerification()) {
            return $block;
        }

        $gateways = app(CompanyPaymentGatewayService::class);
        $active = $gateways->activeConfig($company);

        // Fails closed: only the company's CURRENT provider may be offered,
        // matching exactly what GatewayPaymentService::chargeWithToken() will
        // accept. A page that offers what the charge endpoint refuses is a
        // page that wastes a customer's card details.
        if ($active === null || $active['provider'] !== $provider || ! $order->isPayable() || $order->hasGatewayPayment()) {
            return $block;
        }

        $config = $gateways->configFor($company, $provider);

        if ($config === null) {
            return $block;
        }

        $intent = app(PaymentGatewayRegistry::class)
            ->driver($provider)
            ->startPayment($order, $config['credentials'], $config['is_live']);

        $block['intent'] = [
            'kind' => $intent->kind,
            'amount_satang' => $intent->amountSatang,
            // The PUBLIC key only. The secret never leaves the server, and
            // this one is meant to be in the page's HTML — that is how the
            // card number reaches the provider instead of us.
            'public_key' => $intent->publicKey,
            'redirect_url' => $intent->redirectUrl,
            'extra' => $intent->extra,
        ];

        return $block;
    }
}
