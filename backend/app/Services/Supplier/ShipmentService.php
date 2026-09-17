<?php

namespace App\Services\Supplier;

use App\Enums\OrderStatus;
use App\Enums\ShippingStatus;
use App\Enums\SupplierReleaseTrigger;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 2026-09-16 — the parcel left.
 *
 * Owner: "supplier เป็นคนส่งเอง". The supplier has the goods, so the supplier
 * is the one who can say a parcel has gone — and saying so is what releases
 * their own money on an OnDelivered deal. That is a slightly uncomfortable
 * arrangement (the payee reports the event that triggers payment) and it is
 * the owner's call; what this service can do is make sure the claim is
 * recorded with a name, a time and a tracking number against it, so it is a
 * statement somebody made rather than a flag that changed.
 *
 * ── WHY MARKING SHIPPED REQUIRES A TRACKING NUMBER ──
 *
 * It is the only part of the claim anybody else can check. Without it "I sent
 * it" is unfalsifiable, and on an OnDelivered deal it is unfalsifiable and
 * also worth money.
 */
class ShipmentService
{
    public function __construct(
        private SupplierSettlementService $settlements,
    ) {}

    /**
     * Record a shipment against an order.
     *
     * Refuses rather than silently correcting in three cases, all of which
     * mean the caller has the wrong order:
     *
     *   · the product needs no shipping — there is nothing to send, and
     *     accepting a tracking number would produce a parcel record for a
     *     service appointment;
     *   · the order is not paid — we do not ship what has not been bought;
     *   · it already shipped — a second tracking number would overwrite the
     *     first with no record that it ever existed, and on an OnDelivered
     *     deal the money is already out.
     */
    public function markShipped(Order $order, User $actor, string $trackingNumber): Order
    {
        if (! $order->needsShipping()) {
            throw ValidationException::withMessages([
                'order' => 'สินค้าในคำสั่งซื้อนี้ไม่ใช่สินค้าที่ต้องจัดส่ง',
            ]);
        }

        if ($order->status !== OrderStatus::Paid) {
            throw ValidationException::withMessages([
                'order' => 'บันทึกการจัดส่งได้เฉพาะคำสั่งซื้อที่ชำระเงินแล้วเท่านั้น',
            ]);
        }

        if ($order->shipping_status instanceof ShippingStatus && $order->shipping_status->hasLeft()) {
            throw ValidationException::withMessages([
                'order' => 'คำสั่งซื้อนี้บันทึกการจัดส่งไปแล้ว (เลขพัสดุ: '.($order->tracking_number ?: '—').')',
            ]);
        }

        return DB::transaction(function () use ($order, $actor, $trackingNumber) {
            $order->update([
                'shipping_status' => ShippingStatus::Shipped,
                'tracking_number' => trim($trackingNumber),
                'shipped_at' => now(),
                'shipped_by_user_id' => $actor->id,
            ]);

            /*
             * Same transaction as the status change, for the same reason the
             * voucher redemption releases inside its own: "the parcel went"
             * and "the money for it became payable" are one fact on an
             * OnDelivered deal. A crash between them leaves a supplier who has
             * shipped and cannot be paid, with nothing to show why.
             *
             * A no-op on any other trigger — the service decides, not this.
             */
            $this->settlements->releaseFor($order, SupplierReleaseTrigger::OnDelivered);

            return $order->fresh();
        });
    }

    /**
     * Confirm the parcel arrived.
     *
     * Separate from markShipped because it is a different claim by a different
     * person at a different time, and because nothing about payment hangs on
     * it — OnDelivered releases at SHIPPED (see ShippingStatus::hasLeft()).
     * This is bookkeeping, and it is allowed to be.
     */
    public function markDelivered(Order $order): Order
    {
        if (! ($order->shipping_status instanceof ShippingStatus && $order->shipping_status->hasLeft())) {
            throw ValidationException::withMessages([
                'order' => 'ต้องบันทึกการจัดส่งก่อนจึงจะยืนยันว่าได้รับแล้วได้',
            ]);
        }

        $order->update(['shipping_status' => ShippingStatus::Delivered]);

        return $order->fresh();
    }
}
