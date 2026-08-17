<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Order\OrderVoucherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TASK-193 — one-time data-repair pass for orders that reached Paid before
 * ADR-033/TASK-189 shipped (2026-08-16). OrderVoucherService::issueFor() only
 * ever runs from inside OrderService::confirmPayment(), so any order that was
 * already Paid before that code existed has no voucher and never will unless
 * something backfills it — exactly the gap PublicOrderResource's own comment
 * documents. The human confirmed via `php artisan tinker` that at least one
 * real order (ORD-3HYA3EXB, paid 2026-08-13) is in this state and asked for
 * EVERY paid-but-voucherless order to be fixed in one pass.
 *
 * Deliberately does NOT touch NotificationType::OrderPaymentConfirmed or
 * OrderPaymentConfirmedMail (not imported, not referenced anywhere below) —
 * the human explicitly said not to re-send the payment-confirmation
 * notification/email for backfilled orders. Those already happened (or
 * didn't) for real at the time; this command is a data repair, not a new
 * payment event. This is also the correct read of TASK-190's own spec: that
 * notification/email is scoped to the live confirmPayment() event.
 *
 * withoutGlobalScopes() — same rationale as every other command in this
 * directory (see e.g. BackfillLessonPageCountsCommand, PruneChunkedUploadsCommand):
 * a console command runs with no authenticated user, so TenantScope::apply()
 * is already a no-op (it returns immediately when auth()->user() is null —
 * see app/Models/Scopes/TenantScope.php). Calling withoutGlobalScopes() here
 * just states that explicitly instead of relying on it by accident; it does
 * NOT mean "bypass tenant security" — there is no tenant context to bypass
 * in a console process, and this command must see orders across every
 * company by design (a one-time platform-wide repair, not a per-company
 * action taken by any user).
 *
 * issueFor() reads $order->paid_at for the expiry snapshot calculation.
 * Historical orders already have that column set from when they were
 * originally confirmed, so the expiry backfilled today is computed as if
 * issued at the time of the order's own original payment — matching the
 * "one door" reasoning already in OrderVoucherService's own docblock — not
 * as if the order were paid today.
 *
 * Idempotent by construction: the query only ever selects Paid orders with
 * no voucher row, so re-running after a clean pass finds nothing left to do.
 *
 * Each order's voucher creation runs in its OWN DB::transaction() (not one
 * transaction for the whole batch) and any exception is caught per-order —
 * one bad row must not roll back, or even stop, the rest of the batch.
 */
class BackfillLegacyPaidOrderVouchersCommand extends Command
{
    protected $signature = 'vouchers:backfill-legacy-paid-orders
        {--dry-run : Report what would be backfilled, without writing anything}';

    protected $description = 'Issue a voucher (via the existing OrderVoucherService::issueFor()) for every already-Paid order that has none (TASK-193)';

    public function handle(OrderVoucherService $orderVoucherService): int
    {
        $paidOrdersQuery = Order::withoutGlobalScopes()
            ->where('status', OrderStatus::Paid->value);

        $scanned = (clone $paidOrdersQuery)->count();

        $voucherless = (clone $paidOrdersQuery)
            ->whereDoesntHave('voucher')
            ->get();

        $alreadyHadVoucher = $scanned - $voucherless->count();

        if ($voucherless->isEmpty()) {
            $this->info("สแกนคำสั่งซื้อที่ชำระเงินแล้วทั้งหมด {$scanned} รายการ — ทุกรายการมี voucher อยู่แล้ว ไม่ต้องทำอะไร");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line("[dry-run] สแกนคำสั่งซื้อที่ชำระเงินแล้วทั้งหมด {$scanned} รายการ · มี voucher อยู่แล้ว {$alreadyHadVoucher} รายการ (ข้าม) · จะสร้าง voucher ให้ {$voucherless->count()} รายการ ดังนี้ (ยังไม่มีการบันทึกใดๆ):");

            foreach ($voucherless as $order) {
                $this->line("  · {$order->order_number}");
            }

            return self::SUCCESS;
        }

        $backfilled = 0;
        $failures = [];

        foreach ($voucherless as $order) {
            try {
                DB::transaction(function () use ($order, $orderVoucherService): void {
                    $orderVoucherService->issueFor($order);
                });
                $backfilled++;
            } catch (Throwable $e) {
                // Catch per-order — a single throw here must never abort the
                // loop. DB::transaction() above already rolled back only
                // THIS order's own (uncommitted) work; every prior
                // successfully-backfilled order in this run stays committed.
                $failures[] = [
                    'order_number' => $order->order_number,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->newLine();
        $this->info("สรุป: สแกนทั้งหมด {$scanned} รายการ · มี voucher อยู่แล้ว {$alreadyHadVoucher} รายการ (ข้าม) · สร้างสำเร็จ {$backfilled} รายการ · ล้มเหลว ".count($failures).' รายการ');

        foreach ($failures as $failure) {
            $this->error("  ✗ {$failure['order_number']}: {$failure['error']}");
        }

        return empty($failures) ? self::SUCCESS : self::FAILURE;
    }
}
