<?php

namespace App\Services\Supplier;

use App\Enums\OrderStatus;
use App\Enums\ShippingStatus;
use App\Enums\SupplierReleaseTrigger;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 2026-09-16 — the parcel left. 2026-09-27 — and the recipient got it.
 *
 * Owner (2026-09-16): "supplier เป็นคนส่งเอง". The supplier records the
 * shipment, with a tracking number because that is the only part of "I sent
 * it" anybody else can check.
 *
 * ── WHO RELEASES THE MONEY ON A DELIVER-BEFORE-PAY DEAL (ADR-048) ──
 *
 * Until 2026-09-27 the supplier's own "shipped" click released their money:
 * the payee reported the event that paid them. The owner changed it:
 *
 *   "ต้องแก้ไขเป็นผู้รับกดรับสินค้า ถ้า Agent หรือลูกค้าไม่กดรับสินค้าเกิน 15 วัน
 *    หลัง คู่ค้ากดยืนยันว่าจัดส่งสำเร็จแล้ว พึงเบิกเงินได้"
 *
 * So shipping releases nothing. RECEIPT does — confirmed by the selling agent
 * in the portal or by the customer on the payment link, whoever is first — or,
 * if neither does, by the system once `auto_receive_days` (a platform setting,
 * seeded at the owner's 15) have passed since shipping.
 */
class ShipmentService
{
    public const VIA_AGENT = 'agent';

    public const VIA_CUSTOMER = 'customer';

    public const VIA_AUTO = 'auto';

    public function __construct(
        private SupplierSettlementService $settlements,
        private SupplierPlatformSettingService $settings,
    ) {}

    /**
     * Record a shipment against an order.
     *
     * Refuses rather than silently correcting in three cases, all of which
     * mean the caller has the wrong order:
     *
     *   · the product needs no shipping — there is nothing to send;
     *   · the order is not paid — we do not ship what has not been bought;
     *   · it already shipped — a second tracking number would overwrite the
     *     first with no record that it ever existed.
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

        $order->update([
            'shipping_status' => ShippingStatus::Shipped,
            'tracking_number' => trim($trackingNumber),
            'shipped_at' => now(),
            'shipped_by_user_id' => $actor->id,
        ]);

        return $order->fresh();
    }

    /**
     * The recipient says the parcel arrived — or the window has passed.
     *
     * Releases an OnDelivered deal's money in the same transaction: "it
     * arrived" and "the supplier can now be paid for it" are one fact.
     *
     * Refused on an order that is not Paid (a refunded order is not received
     * into anybody's payout), not shipped yet, or already received.
     */
    public function confirmReceipt(Order $order, string $via, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($order, $via, $actor) {
            $order = Order::withoutGlobalScopes()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::Paid) {
                throw ValidationException::withMessages([
                    'order' => 'ยืนยันรับสินค้าได้เฉพาะคำสั่งซื้อที่ชำระเงินแล้วเท่านั้น',
                ]);
            }

            if ($order->received_at !== null) {
                throw ValidationException::withMessages([
                    'order' => 'คำสั่งซื้อนี้ยืนยันรับสินค้าไปแล้ว',
                ]);
            }

            if ($order->shipping_status !== ShippingStatus::Shipped) {
                throw ValidationException::withMessages([
                    'order' => 'คู่ค้ายังไม่ได้บันทึกการจัดส่งคำสั่งซื้อนี้',
                ]);
            }

            $order->update([
                'shipping_status' => ShippingStatus::Delivered,
                'received_at' => now(),
                'receipt_confirmed_via' => $via,
                'received_by_user_id' => $actor?->id,
            ]);

            $released = $this->settlements->releaseFor($order, SupplierReleaseTrigger::OnDelivered);

            // §6 — this can make supplier money payable.
            AuditLog::create([
                'company_id' => $order->company_id,
                'actor_user_id' => $actor?->id,
                'action' => 'order.receipt_confirmed',
                'auditable_type' => Order::class,
                'auditable_id' => $order->id,
                'old_values' => ['shipping_status' => ShippingStatus::Shipped->value],
                'new_values' => [
                    'shipping_status' => ShippingStatus::Delivered->value,
                    'via' => $via,
                    'supplier_rows_released' => $released,
                ],
                'ip_address' => $via === self::VIA_AUTO ? null : request()?->ip(),
            ]);

            return $order->fresh();
        });
    }

    /**
     * Confirm every parcel nobody confirmed within the platform window.
     *
     * Run by the scheduler daily, AND on the screens and actions that read a
     * supplier's balance — production has not always had its scheduler
     * running, and a supplier's money must not depend on a cron line.
     * Idempotent: a confirmed order is never picked twice.
     *
     * Closed companies are skipped like every other scheduled money job
     * (ADR-047): their work waits until they are reopened.
     */
    public function autoConfirmDue(): int
    {
        $days = $this->settings->autoReceiveDays();

        if ($days === null) {
            return 0;
        }

        $dueIds = Order::withoutGlobalScopes()
            ->where('status', OrderStatus::Paid->value)
            ->where('shipping_status', ShippingStatus::Shipped->value)
            ->whereNull('received_at')
            ->where('shipped_at', '<=', now()->subDays($days))
            ->whereIn('company_id', Company::operational()->select('id'))
            ->pluck('id');

        $confirmed = 0;

        foreach ($dueIds as $id) {
            try {
                $this->confirmReceipt(Order::withoutGlobalScopes()->findOrFail($id), self::VIA_AUTO);
                $confirmed++;
            } catch (ValidationException) {
                // Confirmed by a person between the query and the lock.
            }
        }

        return $confirmed;
    }
}
