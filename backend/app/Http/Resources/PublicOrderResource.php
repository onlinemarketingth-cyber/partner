<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Http\Resources\Concerns\ResolvesPublicTheme;
use App\Models\Company;
use App\Models\Order;
use App\Services\Payment\CompanyPaymentGatewayService;
use App\Services\Payment\Gateways\PaymentIntent;
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
     * Set ONLY by PublicPaymentController::intent(), never by show().
     *
     * A PaymentIntent is not a property of an order — producing one opens a
     * chargeable session at the provider. So this resource never creates one;
     * it renders the one it was handed, and renders `intent: null` the rest
     * of the time. See GatewayPaymentService::beginOnlinePayment().
     */
    private ?PaymentIntent $onlineIntent = null;

    public function withIntent(?PaymentIntent $intent): static
    {
        $this->onlineIntent = $intent;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $company = $this->company;

        /*
         * 2026-09-03 — THE QR NO LONGER DEPENDS ON WHAT THE AGENT PICKED.
         *
         * This used to require the ORDER to say 'promptpay', a choice the
         * agent made when they created it — days before the customer opened
         * the link, and on the customer's behalf. Bank transfer and PromptPay
         * are the same flow settling into the same account; showing both and
         * letting the customer scan or type is strictly better than guessing
         * for them, and `company_payment` below already ships both anyway.
         *
         * Still empty when the company configured no proxy id: there is no
         * payload to build, and a QR that resolves to nothing is worse than
         * no QR.
         */
        $promptpayPayload = '';
        if ($company?->payment_promptpay_id) {
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
     * ── 2026-09-03: THE CUSTOMER CHOOSES, SO THIS DESCRIBES A MENU ──
     *
     * An order used to be born belonging to one gateway, and this block said
     * which. Now bank transfer is always available and the company may also
     * have ONE online gateway switched on, so this block says which of those
     * two the customer may still use:
     *
     *   `transfer_available` — the slip / PromptPay flow is open. What the
     *                          customer needs for it is already in
     *                          `company_payment` + `promptpay_payload` above,
     *                          where ADR-017 put it; describing it twice
     *                          would create two places that must agree.
     *   `online`             — the gateway they may pay a card with, by name,
     *                          or null. Naming it without starting it is the
     *                          whole point: starting it opens a chargeable
     *                          session (see beginOnlinePayment), which must
     *                          not happen just because a page was loaded.
     *   `intent`             — present only on the response to a customer who
     *                          actually pressed the card button.
     *
     * ── FAILS CLOSED ──
     *
     * `online` is null unless the company has a verified, switched-on gateway
     * AND this order can still take money. Offering a card form the charge
     * endpoint would refuse only wastes a customer's card details.
     *
     * @return array<string, mixed>
     */
    private function gatewayBlock(?Company $company): array
    {
        /** @var Order $order */
        $order = $this->resource;

        $open = $company !== null && $order->isPayable() && ! $order->hasGatewayPayment();

        $block = [
            // The gateway this order's money is going through, once one has
            // been chosen. Historical record, not a rule: it stays 'manual'
            // until the customer presses the card button.
            'provider' => $order->payment_provider?->value,
            // A charge made with a test key is not revenue, and the customer
            // is entitled to know which one they are about to make.
            'mode' => $order->gateway_mode,
            'payment_received' => $order->hasGatewayPayment(),
            'transfer_available' => $open,
            'online' => null,
            'intent' => $this->intentBlock(),
        ];

        if (! $open) {
            return $block;
        }

        // activeConfig() never answers with the manual flow (2026-09-03), so
        // this is the ONLINE gateway or nothing.
        $active = app(CompanyPaymentGatewayService::class)->activeConfig($company);

        if ($active === null) {
            return $block;
        }

        $block['online'] = [
            'provider' => $active['provider']->value,
            'label' => $active['provider']->label(),
            // Shown next to the card button, not only after the redirect: a
            // customer typing a real card into a test-mode checkout is a
            // problem worth preventing one screen earlier.
            'mode' => $active['is_live'] ? 'live' : 'test',
        ];

        return $block;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function intentBlock(): ?array
    {
        $intent = $this->onlineIntent;

        if ($intent === null) {
            return null;
        }

        return [
            'kind' => $intent->kind,
            'amount_satang' => $intent->amountSatang,
            // The PUBLIC key only. The secret never leaves the server, and
            // this one is meant to be in the page's HTML — that is how the
            // card number reaches the provider instead of us.
            'public_key' => $intent->publicKey,
            'redirect_url' => $intent->redirectUrl,
            'extra' => $intent->extra,
        ];
    }
}
