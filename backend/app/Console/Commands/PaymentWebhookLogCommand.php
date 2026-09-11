<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\PaymentWebhookEvent;
use Illuminate\Console\Command;

/**
 * 2026-09-11 — everything the gateways have sent lately, in arrival order.
 *
 * `orders:explain` answers "what arrived about THIS order", which is the
 * common question and needs an order to ask it of. This one exists for the
 * cases where that is the wrong shape:
 *
 *   • a payment that matched NOTHING — the event names a token no order has,
 *     so there is no order to run orders:explain against, and this is the
 *     only place the payload can be read from;
 *   • "I just pressed pay — did anything reach us at all?", where an empty
 *     answer is the finding (the endpoint is not configured, or the signature
 *     is failing, and neither leaves a row);
 *   • an event type this system ignores, which by definition never touched
 *     an order.
 *
 * READ-ONLY, like orders:explain, and for the same reason: whoever runs it on
 * a live system is already worried.
 */
class PaymentWebhookLogCommand extends Command
{
    protected $signature = 'payments:webhook-log
                            {--order= : Only events for this order number}
                            {--type= : Only this event type, e.g. checkout.session.completed}
                            {--unmatched : Only events that matched no order at all}
                            {--limit=20 : How many of the most recent events to show}
                            {--raw : Print each payload in full}';

    protected $description = 'Show what the payment gateways actually sent, newest last (read-only)';

    public function handle(): int
    {
        // `with('order')`: TenantScope narrows nothing when there is no
        // authenticated user, which is always the case here — see its own
        // docblock. Eager-loaded so twenty rows are one query, not twenty-one.
        $query = PaymentWebhookEvent::withoutGlobalScopes()->with('order')->orderByDesc('received_at');

        if ($this->option('order') !== null) {
            $order = Order::withoutGlobalScopes()
                ->where('order_number', trim((string) $this->option('order')))
                ->first();

            if ($order === null) {
                $this->error('ไม่พบคำสั่งซื้อ '.$this->option('order'));

                return self::FAILURE;
            }

            $query->where('order_id', $order->id);
        }

        if ($this->option('type') !== null) {
            $query->where('event_type', $this->option('type'));
        }

        if ($this->option('unmatched')) {
            $query->whereNull('order_id');
        }

        $events = $query->limit(max(1, (int) $this->option('limit')))->get()->reverse();

        if ($events->isEmpty()) {
            $this->line('');
            $this->warn('ไม่มีเหตุการณ์จากเกตเวย์ตามเงื่อนไขนี้');
            $this->line('');
            /*
             * The empty answer is a finding, not a dead end, so it names what
             * it rules out. A webhook that never arrives is the single most
             * common cause of "I paid and nothing happened", and it looks
             * exactly like a webhook that arrived and was mishandled unless
             * somebody is told which one this is.
             */
            $this->line('  ถ้าเพิ่งทดสอบชำระเงินมาแล้วยังว่าง แปลว่า webhook ไม่ได้เข้ามาถึงระบบเลย ให้ตรวจ:');
            $this->line('   • URL ใน Stripe Dashboard ชี้มาที่ /api/v1/webhooks/payments/stripe/{company_id} หรือไม่');
            $this->line('   • ลายเซ็นผ่านหรือไม่ — ถ้าไม่ผ่านจะไม่มีแถวที่นี่ แต่จะมีใน storage/logs ว่า "invalid signature"');
            $this->line('   • ระบบเริ่มเก็บข้อมูลนี้ตั้งแต่ migration 2026_09_24 เท่านั้น');

            return self::SUCCESS;
        }

        $this->line('');
        $this->table(
            ['เมื่อ', 'บริษัท', 'ชนิดเหตุการณ์', 'ระบบอ่านว่า', 'คำสั่งซื้อ', 'payment_status', 'เลขที่ชำระ'],
            $events->map(fn (PaymentWebhookEvent $event) => [
                $event->received_at?->toDateTimeString(),
                $event->company_id,
                $event->event_type ?? '—',
                $event->result ?? '—',
                // The unmatched case, said in words rather than as a blank
                // cell — a blank reads as "not loaded yet".
                $event->order?->order_number ?? 'ไม่พบคำสั่งซื้อที่ตรงกัน',
                $this->paymentStatusOf($event) ?? '—',
                $event->charge_id ?? '—',
            ])->all(),
        );

        if (! $this->option('raw')) {
            $this->line('  เพิ่ม --raw เพื่อดู payload เต็ม ๆ');

            return self::SUCCESS;
        }

        foreach ($events as $event) {
            $this->line('');
            $this->info('── '.$event->received_at?->toDateTimeString().' · '.($event->event_type ?? '—').' · '.($event->event_id ?? '—'));
            $this->line(json_encode(
                $event->payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?: '(อ่าน payload ไม่ได้)');
        }

        $this->line('');
        $this->line('  หมายเหตุ: ค่าที่ชื่อบอกว่าเป็นความลับ (เช่น client_secret) ถูกแทนด้วย [redacted] ตั้งแต่ตอนบันทึก');

        return self::SUCCESS;
    }

    private function paymentStatusOf(PaymentWebhookEvent $event): ?string
    {
        $status = data_get($event->payload, 'data.object.payment_status');

        return is_string($status) ? $status : null;
    }
}
