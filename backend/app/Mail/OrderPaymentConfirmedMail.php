<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * TASK-190 §4.2 — the FIRST Mailable in this app (app/Mail/ did not exist
 * before this task). Sent to `order.client.email` from
 * OrderController::confirm(), AFTER OrderService::confirmPayment()'s
 * transaction has already committed — never from inside that Service/
 * transaction (§4.3: a slow/failing SMTP call must not hold the DB
 * transaction open, and a rollback must never have already sent an email).
 *
 * Content is short Thai text + the `/pay/{token}` link ONLY — no voucher/
 * QR re-render. §4.2 / ADR-033 §2.4's "one delivery surface" reasoning:
 * the email is a notification that something is ready, not a second place
 * the voucher is rendered, which would drift the moment either place
 * changes independently.
 *
 * DELIBERATELY NOT ShouldQueue (§4.4 / ADR-004 — queue:work isn't
 * guaranteed running in every environment; queuing risks this silently
 * never sending with no visible failure). The `Queueable` trait is still
 * used (it's on the framework's own Mailable stub regardless of whether
 * ShouldQueue is implemented) but nothing in this class opts into a queue.
 *
 * DELIBERATELY built with Content::htmlString() rather than a Blade view —
 * CLAUDE.md §3 keeps this backend a strict JSON API with Blade templating
 * "strictly forbidden". That rule is about HTTP responses to the SPA, not
 * SMTP bodies, but a one-line transactional email doesn't need a view file
 * either way, and not adding one keeps the "no Blade in this repo" rule
 * unambiguous rather than carving out a quiet exception.
 */
class OrderPaymentConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "ยืนยันการชำระเงินแล้ว - คำสั่งซื้อ {$this->order->order_number}",
        );
    }

    public function content(): Content
    {
        $frontendUrl = rtrim((string) config('services.agent_portal.frontend_url'), '/');
        $payUrl = "{$frontendUrl}/pay/{$this->order->public_token}";

        $html = '<p>เรียนคุณลูกค้า</p>'
            .'<p>คำสั่งซื้อหมายเลข <strong>'.e($this->order->order_number).'</strong> ได้รับการยืนยันการชำระเงินเรียบร้อยแล้ว</p>'
            .'<p><a href="'.e($payUrl).'">ดูรายละเอียดคำสั่งซื้อ</a></p>';

        return new Content(htmlString: $html);
    }
}
