<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Resources\Concerns\ResolvesPublicTheme;
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
}
