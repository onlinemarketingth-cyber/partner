<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\OrderVoucher;
use Illuminate\Support\Str;

/**
 * ADR-033 (TASK-189) §2.2/§2.4 — mints the post-payment service-access
 * voucher. Called from inside OrderService::confirmPayment()'s existing
 * DB::transaction, ONLY when the referral had not already reached Complete
 * Payment (the same `$alreadyClosed` idempotency guard that stops BR-4
 * commission from double-firing on a re-confirm) — so a paid order always
 * has exactly one voucher.
 */
class OrderVoucherService
{
    /**
     * Snapshot usage_quota/expires_at from `product` at THIS moment —
     * never read live at redemption time (ADR-033 §2.2/§2.4, same
     * reasoning as orders.amount_satang snapshotting price at ADR-017).
     * `$order->paid_at` must already be set by the caller.
     */
    public function issueFor(Order $order): OrderVoucher
    {
        $product = $order->product;

        return OrderVoucher::create([
            'order_id' => $order->id,
            'code' => $this->generateCode(),
            'usage_quota' => $product->voucher_usage_quota,
            'used_count' => 0,
            'expires_at' => $product->voucher_validity_days !== null
                ? (clone $order->paid_at)->addDays($product->voucher_validity_days)
                : null,
        ]);
    }

    /** Unguessable 40-char redemption code — same Str::random treatment as orders.public_token. */
    private function generateCode(): string
    {
        do {
            $code = Str::random(40);
        } while (OrderVoucher::where('code', $code)->exists());

        return $code;
    }
}
