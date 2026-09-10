<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Order;
use Illuminate\Console\Command;

/**
 * 2026-09-10 — "how did THIS order become paid?", answerable in one command.
 *
 * Prompted by a question the system could not answer from the outside: a card
 * was declined in testing and the order looked paid, and nothing on any screen
 * could say whether that came from a gateway event, a person pressing approve
 * in the console, or an earlier successful attempt on the same order.
 *
 * Each of those leaves a different fingerprint, and all of them were already in
 * the database — spread across three tables and readable only by somebody
 * willing to write SQL against production. This prints them together.
 *
 * READ-ONLY, deliberately and completely. It writes nothing, changes nothing,
 * and takes no flags that could. A tool for answering "what happened" must be
 * safe to run on a live system by somebody who is already worried.
 */
class ExplainOrderPaymentCommand extends Command
{
    protected $signature = 'orders:explain {order : Order number, e.g. ORD-ZSZZVPPV}';

    protected $description = 'Show how one order reached its current payment state (read-only)';

    public function handle(): int
    {
        $number = trim((string) $this->argument('order'));

        // withoutGlobalScopes: run from the command line there is no
        // authenticated user for TenantScope to key on, and the person asking
        // is on the server.
        $order = Order::withoutGlobalScopes()
            ->with(['company', 'agent', 'verifiedBy', 'client', 'product', 'voucher'])
            ->where('order_number', $number)
            ->first();

        if ($order === null) {
            $this->error("ไม่พบคำสั่งซื้อ {$number}");

            return self::FAILURE;
        }

        $this->line('');
        $this->info("คำสั่งซื้อ {$order->order_number} · {$order->company?->name}");
        $this->line('');

        $this->table(['', ''], [
            ['สถานะ', $order->status->value.' ('.$order->status->label().')'],
            ['ยอด', number_format($order->amount_satang / 100, 2).' บาท'],
            ['ช่องทางที่เลือก', $order->payment_method->value],
            ['เกตเวย์', $order->payment_provider?->value ?? '— (ยังไม่เคยกดจ่ายด้วยบัตร)'],
            ['โหมด', $order->gateway_mode ?? '—'],
            /*
             * THE DECIDING FIELD. A charge id means a gateway told us money
             * moved; no charge id on a paid order means a person confirmed it
             * in the console. Those are the only two ways an order becomes
             * paid, and this line separates them.
             */
            ['เลขที่การชำระจากเกตเวย์', $order->gateway_charge_id ?? '— (ไม่มี = ไม่ได้มาจากเกตเวย์)'],
            ['ชำระเมื่อ', $order->paid_at?->toDateTimeString() ?? '—'],
            ['ยืนยันโดย', $order->verifiedBy?->name ?? '—'],
            ['ตัวแทน', $order->agent?->name ?? '—'],
            ['ความผิดพลาดล่าสุด', $order->last_payment_error ?? '—'],
            ['เมื่อ', $order->last_payment_error_at?->toDateTimeString() ?? '—'],
            ['บัตรกำนัล', $order->voucher?->code ?? '— (ยังไม่ออก)'],
        ]);

        $this->line('');
        $this->info('ประวัติที่บันทึกไว้');

        $rows = AuditLog::withoutGlobalScopes()
            ->with('actor')
            ->where('auditable_type', Order::class)
            ->where('auditable_id', $order->id)
            ->orderBy('created_at')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  (ไม่มีรายการ)');

            return self::SUCCESS;
        }

        $this->table(
            ['เมื่อ', 'เหตุการณ์', 'โดย'],
            $rows->map(fn (AuditLog $row) => [
                $row->created_at?->toDateTimeString(),
                $row->action,
                // Null actor is the honest answer for a gateway event: no
                // person did it, and naming one would put somebody's name
                // against something they never touched.
                $row->actor?->name ?? 'ระบบ / เกตเวย์',
            ])->all(),
        );

        $this->line('');
        $this->line('  หมายเหตุ: เหตุการณ์จากเกตเวย์ (ชำระสำเร็จ / ไม่สำเร็จ / หมดเวลา / คืนเงิน)');
        $this->line('  จะอยู่ใน storage/logs ด้วย — ค้นคำว่า "Payment webhook applied" พร้อมเลขคำสั่งซื้อนี้');

        return self::SUCCESS;
    }
}
