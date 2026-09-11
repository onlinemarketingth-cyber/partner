<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\PaymentWebhookEvent;
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
    protected $signature = 'orders:explain
                            {order : Order number, e.g. ORD-ZSZZVPPV}
                            {--raw : Print the gateway payloads in full, exactly as they arrived}
                            {--events=10 : How many of the most recent gateway events to show}';

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
        } else {
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
        }

        $this->printGatewayEvents($order);

        return self::SUCCESS;
    }

    /**
     * What the gateway actually sent about this order.
     *
     * ── 2026-09-11 (human: "ขึ้นค่า debug จริงว่า stripe คืนค่าอะไรมา") ──
     *
     * Everything above this line is what THIS SYSTEM concluded. When an order
     * looks paid after a card was refused, our conclusion is the thing in
     * doubt, and reading it back in a nicer format proves nothing. This
     * section is the gateway's own words: one row per signature-verified
     * delivery, kept by PaymentWebhookRecorder.
     *
     * The summary names the field that decides everything on Stripe —
     * `payment_status` — because the event TYPE does not: a declined card
     * also finishes a checkout session, and `checkout.session.completed`
     * arrives either way.
     */
    private function printGatewayEvents(Order $order): void
    {
        $limit = max(1, (int) $this->option('events'));

        $events = PaymentWebhookEvent::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->orderByDesc('received_at')
            ->limit($limit)
            ->get()
            ->reverse();

        $this->line('');
        $this->info('ข้อมูลดิบที่เกตเวย์ส่งมา (ล่าสุด '.$limit.' รายการ)');

        if ($events->isEmpty()) {
            $this->line('  (ไม่มีรายการ)');
            $this->line('');
            /*
             * Said plainly, because the empty case has TWO meanings and they
             * lead opposite ways. An order paid before this table existed has
             * nothing here and never will; an order paid after it does, and
             * an empty list then is itself the finding — no gateway event
             * ever arrived, so something else marked it paid.
             */
            $this->line('  ว่างเปล่าหมายถึงอย่างใดอย่างหนึ่ง:');
            $this->line('   • คำสั่งซื้อนี้เกิดก่อนที่ระบบจะเริ่มเก็บข้อมูลดิบ (migration 2026_09_24) — ดูใน Stripe Dashboard แทน');
            $this->line('   • หรือไม่เคยมี webhook เข้ามาเลย แปลว่าสถานะนี้ไม่ได้มาจากเกตเวย์');

            return;
        }

        $this->table(
            ['เมื่อ', 'ชนิดเหตุการณ์', 'ระบบอ่านว่า', 'payment_status', 'เลขที่ชำระ', 'ยอด (สตางค์)'],
            $events->map(fn (PaymentWebhookEvent $event) => [
                $event->received_at?->toDateTimeString(),
                $event->event_type ?? '—',
                $event->result ?? '—',
                // THE deciding field on Stripe, pulled out of the body so it
                // is readable without --raw.
                $this->paymentStatusOf($event) ?? '—',
                $event->charge_id ?? '—',
                $event->amount_satang ?? '—',
            ])->all(),
        );

        if (! $this->option('raw')) {
            $this->line('');
            $this->line('  เพิ่ม --raw เพื่อดู payload เต็ม ๆ ที่เกตเวย์ส่งมา');

            return;
        }

        foreach ($events as $event) {
            $this->line('');
            $this->info('── '.$event->received_at?->toDateTimeString().' · '.($event->event_type ?? '—').' · '.($event->event_id ?? '—'));
            // JSON_PRETTY_PRINT and unescaped: this is meant to be read by a
            // person, and ไทย is not Thai to anybody.
            $this->line(json_encode(
                $event->payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?: '(อ่าน payload ไม่ได้)');
        }

        $this->line('');
        $this->line('  หมายเหตุ: ค่าที่ชื่อบอกว่าเป็นความลับ (เช่น client_secret) ถูกแทนด้วย [redacted] ตั้งแต่ตอนบันทึก');
    }

    /** Stripe puts it on the session object; other providers simply have none. */
    private function paymentStatusOf(PaymentWebhookEvent $event): ?string
    {
        $status = data_get($event->payload, 'data.object.payment_status');

        return is_string($status) ? $status : null;
    }
}
