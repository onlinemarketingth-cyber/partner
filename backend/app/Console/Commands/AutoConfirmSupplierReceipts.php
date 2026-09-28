<?php

namespace App\Console\Commands;

use App\Services\Supplier\ShipmentService;
use Illuminate\Console\Command;

/**
 * 2026-09-27 — ADR-048. Confirm receipt on every shipped parcel nobody
 * confirmed within the platform's auto-receive window.
 *
 * Owner: "ถ้า Agent หรือลูกค้าไม่กดรับสินค้าเกิน 15 วันหลัง คู่ค้ากดยืนยันว่า
 * จัดส่งสำเร็จแล้ว พึงเบิกเงินได้". The window is the platform setting
 * (supplier_platform_settings.auto_receive_days), not a constant.
 *
 * Scheduled daily. The same sweep also runs whenever a supplier balance is
 * read or a payout raised, so the money does not wait on this schedule.
 */
class AutoConfirmSupplierReceipts extends Command
{
    protected $signature = 'suppliers:auto-confirm-receipts';

    protected $description = 'Mark shipped supplier orders as received once the auto-receive window has passed (ADR-048)';

    public function handle(ShipmentService $shipments): int
    {
        $confirmed = $shipments->autoConfirmDue();

        $this->info("Auto-confirmed receipt on {$confirmed} order(s).");

        return self::SUCCESS;
    }
}
