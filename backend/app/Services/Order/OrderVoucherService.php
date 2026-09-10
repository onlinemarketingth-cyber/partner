<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\OrderVoucher;
use App\Support\VoucherCode;
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

    /**
     * The code a person types at the counter.
     *
     * 2026-09-10 — six characters, not the original 40 (human: "Admin ที่ใช้
     * บัตร voucher นั้นต้องใช้วิธี Key ทำให้รหัสสั้นลงไม่เกิน 6 ตัวได้หรือไม่").
     * App\Support\VoucherCode's docblock carries the reasoning, including why
     * six is safe for THIS code and not for the pay-page token beside it.
     *
     * Vouchers issued before today keep their 40-character codes and keep
     * working: nothing rewrites them, and the lookup accepts both. A customer
     * holding a card printed last week must not find it refused because the
     * format changed.
     */
    private function generateCode(): string
    {
        // 1.07 billion codes against a few thousand vouchers, so a collision is
        // vanishingly unlikely — but it is a UNIQUE column, and "vanishingly
        // unlikely" becomes a failed sale at the till rather than a retry if
        // nobody checks. The cap stops a broken generator spinning forever.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = VoucherCode::generate();

            if (! OrderVoucher::where('code', $code)->exists()) {
                return $code;
            }
        }

        // Twenty collisions in a row is not luck, it is a bug (a generator
        // returning a constant, say). Falling back to a long random code keeps
        // the sale working and leaves evidence in the data that this happened.
        return Str::random(40);
    }
}
