<?php

namespace App\Mail;

use App\Models\Order;
use App\Support\PortalOrigin;
use App\Support\VoucherCode;
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
 * ── 2026-09-10: THE VOUCHER CODE IS NOW IN THE EMAIL ──
 *
 * Human question that prompted it: "หลังจากชำระเงินสำเร็จใน frontend แล้ว
 * ลูกค้าจะได้รหัสยืนยันใช้บริการได้อย่างไร".
 *
 * The answer was: only by still having the /pay/{token} link open. This class
 * previously carried the order number and that link and nothing else, on the
 * "one delivery surface" reasoning (§4.2 / ADR-033 §2.4) — the email announces
 * that something is ready, the page renders it.
 *
 * That reasoning was about DRIFT: two places rendering a voucher's state
 * disagree the moment either changes. It does not hold for the code itself,
 * which is minted once and never changes — and the cost of leaving it out was
 * that a customer who closed the tab had no way back to the thing they had
 * paid for. So the code, what it entitles them to, and when it expires are
 * here; everything mutable — how many uses are left, whether it has been
 * redeemed — stays on the page, and the email says to look there for it.
 *
 * The QR stays on the page too, for a plainer reason: rendering one here means
 * an embedded image, which a mail client may block or strip, so a QR in an
 * email is a picture that is sometimes silently absent. The code is text and
 * always arrives.
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
        /*
         * PortalOrigin, not the canonical config value: a customer who bought
         * on a parked alias must be sent back to the domain they bought from.
         * A receipt that links to a brand they have never seen reads as
         * phishing and gets deleted — see that class.
         */
        $payUrl = PortalOrigin::payUrl($this->order);

        $html = '<p>เรียนคุณลูกค้า</p>'
            .'<p>คำสั่งซื้อหมายเลข <strong>'.e($this->order->order_number).'</strong> ได้รับการยืนยันการชำระเงินเรียบร้อยแล้ว</p>'
            .$this->voucherBlock()
            .'<p><a href="'.e($payUrl).'">ดูรายละเอียดคำสั่งซื้อและ QR สำหรับเข้ารับบริการ</a></p>';

        return new Content(htmlString: $html);
    }

    /**
     * The code, what it is for, and when it stops working.
     *
     * Empty when there is no voucher — which is a real state, not a gap: a
     * re-confirmed order does not mint a second one (ADR-033 §2.2/B1), and an
     * order confirmed before that feature existed has none at all. Inventing a
     * line for those would promise a code that does not exist.
     */
    private function voucherBlock(): string
    {
        $voucher = $this->order->voucher;

        if ($voucher === null) {
            return '';
        }

        /*
         * 2026-09-10 — the code is six characters now, so it is set large and
         * spaced, in the ABC-123 grouping it is printed in everywhere else.
         * Somebody reads this off a phone at a counter, or over the phone to
         * a member of staff; `word-break: break-all` and 16px were sized for
         * the 40-character token this replaced (which older orders still
         * carry, and VoucherCode::format leaves alone).
         */
        $lines = '<p style="margin:0 0 6px 0">รหัสเข้ารับบริการของคุณ</p>'
            .'<p style="margin:0 0 10px 0;font-family:monospace;font-size:28px;font-weight:bold;'
            .'letter-spacing:3px;word-break:break-all;padding:14px;background:#f1f5f9;border-radius:8px">'
            .e(VoucherCode::format($voucher->code)).'</p>';

        if (filled($this->order->product?->name)) {
            $lines .= '<p style="margin:0 0 4px 0">ใช้สำหรับ: <strong>'.e($this->order->product->name).'</strong></p>';
        }

        if ($voucher->usage_quota !== null) {
            $lines .= '<p style="margin:0 0 4px 0">จำนวนสิทธิ์: '.e((string) $voucher->usage_quota).' ครั้ง</p>';
        }

        // Only when there is one. "ไม่มีวันหมดอายุ" is a promise this system
        // would be making on the product's behalf, and a product that gains an
        // expiry later would make every earlier email retroactively wrong.
        if ($voucher->expires_at !== null) {
            $lines .= '<p style="margin:0 0 4px 0">ใช้ได้ถึง: <strong>'
                .e($voucher->expires_at->timezone(config('app.timezone'))->format('d/m/Y'))
                .'</strong></p>';
        }

        return '<div style="margin:16px 0;padding:14px;border:1px solid #e2e8f0;border-radius:12px">'
            .$lines
            .'<p style="margin:8px 0 0 0;font-size:12px;color:#64748b">'
            .'แสดงรหัสนี้ (หรือ QR ในลิงก์ด้านล่าง) ให้เจ้าหน้าที่เพื่อเข้ารับบริการ '
            .'จำนวนสิทธิ์คงเหลือดูได้จากลิงก์ด้านล่างเสมอ</p>'
            .'</div>';
    }
}
