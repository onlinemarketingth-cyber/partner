<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Product;
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
 * CustomerPaymentConfirmationMailer, AFTER OrderService::confirmPayment()'s
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
 * ══ 2026-09-11 — REBUILT TO THE SHAPE A RECEIPT IS NORMALLY BUILT IN ══
 *
 * (human: "ปรับ email ให้เป็นรูปแบบสากล เรียน ใส่ชื่อลูกค้าจากระบบ รวมถึง link
 * ดูรายละเอียดการสั่งซื้อ และ QR สร้างเป็น UI แบบปุ่มให้ชัดเจน")
 *
 * What it was: a stack of bare <p> tags at full window width, opening "เรียน
 * คุณลูกค้า" to somebody whose name we hold, with the one action — the link
 * back to the voucher and its QR — as an underlined sentence at the bottom,
 * indistinguishable from body text.
 *
 * Three things fixed, and each is a rule rather than a decoration:
 *
 *  1. ADDRESSED TO A PERSON. The client's name is in the database; using
 *     "คุณลูกค้า" while holding it reads as bulk mail, which is what people
 *     delete. The generic form survives only as the fallback for an order with
 *     no name on file.
 *
 *  2. THE RECEIPT FACTS ARE A TABLE. Order number, product, amount paid, when.
 *     A customer forwards this to their accounts department or keeps it
 *     against a card statement; those four lines are the whole reason to keep
 *     an email like this at all.
 *
 *  3. ONE BUTTON, NOT A LINK IN A PARAGRAPH. It is the only action in the
 *     message, so it is the only thing on the page that looks pressable.
 *
 * ── WHY IT IS ALL TABLES AND INLINE STYLES ──
 *
 * This is 2005-era HTML on purpose, and every ugly part of it is load-bearing:
 *
 *   • Gmail strips <style> blocks and <head> entirely, so every rule is an
 *     inline `style=` attribute. There is no other way for a rule to survive.
 *   • Outlook renders through Word, which has no flexbox, no grid, and ignores
 *     max-width on a <div>. Width comes from a table with a fixed width, held
 *     inside a 100%-wide wrapper table that centres it — which is also what
 *     fixes the overflow the human's screenshot showed, where the code box ran
 *     off the right edge of the window.
 *   • The button is a table cell with a background colour and padding, not an
 *     <a> with padding: padding on an anchor collapses in several clients, and
 *     a background colour that fails to render would leave invisible white
 *     text on white.
 *   • No remote images anywhere — mail clients block them by default, so a
 *     logo would be an empty box on first open for most recipients, and a QR
 *     would be a missing picture in place of the thing they paid for.
 *   • A hidden preheader line, because the inbox preview otherwise shows
 *     "เรียน คุณ…" — the one part of the message that carries no information.
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
 * SMTP bodies, but not adding a view keeps the "no Blade in this repo" rule
 * unambiguous rather than carving out a quiet exception.
 */
class OrderPaymentConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    /** Page background — never pure white, so the card has an edge to sit on. */
    private const CANVAS = '#f4f5f7';

    private const CARD = '#ffffff';

    private const BORDER = '#e3e6ea';

    private const INK = '#1a1d21';

    private const INK_MUTED = '#5b6470';

    /** The button and the rule under the masthead. Dark, so white text on it is legible everywhere. */
    private const ACTION = '#15181c';

    /** The one accent, echoing the pay page this email links to. */
    private const ACCENT = '#b39355';

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

        /*
         * ORDER OF THE BLOCKS IS THE POINT.
         *
         * The receipt facts, the code, then the one action — all above the
         * product's own description, which 2026-09-11 added and which can run
         * to several paragraphs. Putting the details first would push the
         * button below the fold on a phone, and the button is the only thing
         * in this message a customer ever needs to press.
         */
        $body = $this->greeting()
            .'<p style="margin:0 0 20px 0;font-size:15px;line-height:1.7;color:'.self::INK_MUTED.'">'
            .'เราได้รับการชำระเงินสำหรับคำสั่งซื้อของคุณเรียบร้อยแล้ว ขอบคุณที่ใช้บริการ</p>'
            .$this->summaryTable()
            .$this->voucherBlock()
            .$this->actionButton($payUrl)
            .$this->linkFallback($payUrl)
            .$this->productDetails();

        return new Content(htmlString: $this->document($body));
    }

    /**
     * The outer shell: preheader, centring wrapper, card, masthead, footer.
     *
     * One method rather than fragments scattered through content(), because a
     * mis-closed <table> in an email does not throw — it silently renders as a
     * blank message in exactly one client, which is the sort of bug that is
     * found by a customer rather than by us.
     */
    private function document(string $body): string
    {
        $company = e($this->order->company?->name ?? '');
        $preheader = $this->preheaderText();

        return '<!DOCTYPE html><html lang="th"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="color-scheme" content="light only"><title>ยืนยันการชำระเงิน</title></head>'
            .'<body style="margin:0;padding:0;background:'.self::CANVAS.';">'

            /*
             * The inbox preview text. Hidden in the body itself — height/
             * opacity/overflow all at zero, because no single property is
             * honoured by every client — and followed by padding characters so
             * the client does not pull the greeting in after it.
             */
            .'<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all">'
            .$preheader.str_repeat('&#8203;&nbsp;', 30).'</div>'

            // 100% wrapper + fixed-width inner table: the only centring that
            // works in Outlook.
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            .'style="background:'.self::CANVAS.';padding:24px 12px">'
            .'<tr><td align="center">'
            .'<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" '
            .'style="width:100%;max-width:600px;background:'.self::CARD.';border:1px solid '.self::BORDER.';'
            .'border-radius:14px;overflow:hidden;'
            // A font stack, not a webfont: @font-face is stripped almost
            // everywhere, and Thai falls back to whatever the device has.
            .'font-family:-apple-system,\'Segoe UI\',\'Helvetica Neue\',\'Noto Sans Thai\',Tahoma,sans-serif">'

            // ── Masthead ──
            .'<tr><td style="padding:22px 28px 0 28px">'
            .($company !== ''
                ? '<p style="margin:0;font-size:13px;font-weight:bold;letter-spacing:1.5px;text-transform:uppercase;color:'.self::INK.'">'.$company.'</p>'
                : '')
            .'<div style="height:3px;width:44px;background:'.self::ACCENT.';margin:10px 0 0 0;font-size:0;line-height:0">&nbsp;</div>'
            .'</td></tr>'

            .'<tr><td style="padding:20px 28px 28px 28px">'
            .'<h1 style="margin:0 0 14px 0;font-size:21px;line-height:1.4;color:'.self::INK.'">ชำระเงินเรียบร้อยแล้ว</h1>'
            .$body
            .'</td></tr>'

            // ── Footer ──
            .'<tr><td style="padding:18px 28px;background:'.self::CANVAS.';border-top:1px solid '.self::BORDER.'">'
            .'<p style="margin:0;font-size:12px;line-height:1.6;color:'.self::INK_MUTED.'">'
            .($company !== '' ? $company.'<br>' : '')
            .'อีเมลฉบับนี้ส่งอัตโนมัติ กรุณาอย่าตอบกลับ หากต้องการความช่วยเหลือ กรุณาติดต่อเจ้าหน้าที่ที่ดูแลคุณ'
            .'</p></td></tr>'

            .'</table></td></tr></table></body></html>';
    }

    /**
     * What the inbox shows beside the subject.
     *
     * The code when there is one — that is what the customer is opening the
     * message to find, and putting it here means they often do not have to.
     */
    private function preheaderText(): string
    {
        $voucher = $this->order->voucher;

        return $voucher !== null
            ? 'รหัสเข้ารับบริการของคุณคือ '.e(VoucherCode::format($voucher->code))
            : 'คำสั่งซื้อ '.e($this->order->order_number).' ได้รับการยืนยันการชำระเงินแล้ว';
    }

    /**
     * "เรียน คุณสมชาย ใจดี" — the name this system already holds.
     *
     * Falls back to the generic form ONLY when there is no name: an order an
     * agent keyed in by hand may have none, and "เรียน คุณ" trailing into
     * nothing is worse than "เรียนคุณลูกค้า".
     *
     * The คุณ prefix is skipped when the stored name already carries one, so
     * nobody is addressed as "คุณคุณสมชาย".
     */
    private function greeting(): string
    {
        $name = trim((string) ($this->order->client?->name ?? ''));

        $line = $name === ''
            ? 'เรียน คุณลูกค้า'
            : 'เรียน '.(str_starts_with($name, 'คุณ') ? $name : 'คุณ'.$name);

        return '<p style="margin:0 0 10px 0;font-size:15px;color:'.self::INK.'">'.e($line).'</p>';
    }

    /**
     * The four facts somebody keeps a receipt for.
     *
     * A table with labels in one column, because this is the part that gets
     * forwarded to an accounts department or held up against a card
     * statement — and because "฿8,900" on its own answers nothing.
     */
    private function summaryTable(): string
    {
        $rows = [
            ['เลขที่คำสั่งซื้อ', $this->order->order_number],
        ];

        /*
         * effectiveName(), never ->name. ADR-036 §2: once a product is linked
         * to a shared catalog item, its OWN name column is vestigial — a
         * receipt reading it prints whatever was there before the link, or
         * nothing at all. Product's resolver docblock lists every consumer
         * that has to go through it; this file is one of them.
         */
        if (filled($productName = $this->order->product?->effectiveName())) {
            $rows[] = ['รายการ', $productName];
        }

        // BR-3 — satang all the way through the system; divided only here,
        // for display.
        $rows[] = ['ยอดชำระ', '฿'.number_format($this->order->amount_satang / 100, 2)];

        if ($this->order->paid_at !== null) {
            $rows[] = [
                'ชำระเมื่อ',
                $this->order->paid_at->timezone(config('app.timezone'))->format('d/m/Y H:i').' น.',
            ];
        }

        $html = '';
        foreach ($rows as [$label, $value]) {
            $html .= '<tr>'
                .'<td style="padding:7px 0;font-size:13px;color:'.self::INK_MUTED.';white-space:nowrap;vertical-align:top">'
                .e($label).'</td>'
                .'<td style="padding:7px 0;font-size:14px;font-weight:bold;color:'.self::INK.';text-align:right;vertical-align:top">'
                .e((string) $value).'</td>'
                .'</tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            .'style="width:100%;border:1px solid '.self::BORDER.';border-radius:10px;padding:6px 14px;margin:0 0 20px 0">'
            .$html.'</table>';
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
        $lines = '<p style="margin:0 0 8px 0;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:'.self::INK_MUTED.'">'
            .'รหัสเข้ารับบริการของคุณ</p>'
            .'<p style="margin:0 0 14px 0;font-family:\'SF Mono\',Menlo,Consolas,monospace;font-size:30px;font-weight:bold;'
            .'letter-spacing:4px;word-break:break-all;color:'.self::INK.'">'
            .e(VoucherCode::format($voucher->code)).'</p>';

        if (filled($productName = $this->order->product?->effectiveName())) {
            $lines .= '<p style="margin:0 0 4px 0;font-size:13px;color:'.self::INK_MUTED.'">ใช้สำหรับ: <strong style="color:'.self::INK.'">'
                .e($productName).'</strong></p>';
        }

        if ($voucher->usage_quota !== null) {
            $lines .= '<p style="margin:0 0 4px 0;font-size:13px;color:'.self::INK_MUTED.'">จำนวนสิทธิ์: <strong style="color:'.self::INK.'">'
                .e((string) $voucher->usage_quota).' ครั้ง</strong></p>';
        }

        // Only when there is one. "ไม่มีวันหมดอายุ" is a promise this system
        // would be making on the product's behalf, and a product that gains an
        // expiry later would make every earlier email retroactively wrong.
        if ($voucher->expires_at !== null) {
            $lines .= '<p style="margin:0 0 4px 0;font-size:13px;color:'.self::INK_MUTED.'">ใช้ได้ถึง: <strong style="color:'.self::INK.'">'
                .e($voucher->expires_at->timezone(config('app.timezone'))->format('d/m/Y'))
                .'</strong></p>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0 0 20px 0">'
            .'<tr><td style="padding:18px;background:'.self::CANVAS.';border:1px solid '.self::BORDER.';border-radius:12px">'
            .$lines
            .'<p style="margin:10px 0 0 0;font-size:12px;line-height:1.6;color:'.self::INK_MUTED.'">'
            .'แสดงรหัสนี้ (หรือ QR ในลิงก์ด้านล่าง) ให้เจ้าหน้าที่เพื่อเข้ารับบริการ '
            .'จำนวนสิทธิ์คงเหลือดูได้จากลิงก์ด้านล่างเสมอ</p>'
            .'</td></tr></table>';
    }

    /**
     * The one action in the message.
     *
     * A table cell with a background and padding — NOT an <a> with padding,
     * which collapses in Outlook and in some Android clients, leaving a bare
     * underlined string where a button should be. The anchor fills the cell so
     * the whole rectangle is the hit area.
     */
    private function actionButton(string $url): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 14px 0">'
            .'<tr><td align="center" bgcolor="'.self::ACTION.'" '
            .'style="background:'.self::ACTION.';border-radius:10px">'
            .'<a href="'.e($url).'" '
            .'style="display:inline-block;padding:14px 26px;font-size:15px;font-weight:bold;'
            // Colour on the anchor too: some clients restyle link text, and a
            // blue link on a near-black button is unreadable.
            .'color:#ffffff;text-decoration:none">'
            .'ดูรายละเอียดคำสั่งซื้อ และ QR เข้ารับบริการ</a>'
            .'</td></tr></table>';
    }

    /**
     * 2026-09-11 (human: "ขอรายละเอียด ชื่อ รายละเอียดสินค้า เข้าไปใน email
     * ด้วยครับ").
     *
     * What they actually bought, in the receipt they keep — the description,
     * the spec notes, and the spec sheet — so the email answers "what was this
     * ฿8,900 for" a year later without needing the link to still resolve.
     *
     * ── THE ONE PLACE IN THIS FILE THAT DOES NOT CALL e() ──
     *
     * `description` and `spec_description` are already HTML, and escaping them
     * would print `<p>` at a customer. They are safe to embed for one specific
     * reason, not as a judgement call: App\Support\RichText cleans these
     * fields ON WRITE against a twelve-element allowlist, so the column
     * physically cannot hold anything else — that class's docblock explains
     * why the gate is at write time rather than here. Every other value below
     * (spec keys and values, typed freely into a form, never through that
     * gate) IS escaped.
     *
     * ── AND WHY THE MARKUP IS RESTYLED ON THE WAY OUT ──
     *
     * A <style> block would be stripped by Gmail, so the editor's <p>, <h2>
     * and <li> would arrive with whatever default the mail client has — 1em
     * margins, Times New Roman in a few of them. Rewriting the opening tags is
     * only safe because the allowlist is fixed and tiny; if RichText ever
     * gains an element, it gets a line here or it renders unstyled.
     */
    private function productDetails(): string
    {
        $product = $this->order->product;

        if ($product === null) {
            return '';
        }

        $sections = $this->richText($product->effectiveDescription())
            .$this->richText($product->effectiveSpecDescription())
            .$this->specSheet($product);

        // Nothing worth a heading. A section header over an empty box reads as
        // content that failed to load.
        if (trim($sections) === '') {
            return '';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:26px 0 0 0">'
            .'<tr><td style="padding-top:20px;border-top:1px solid '.self::BORDER.'">'
            .'<p style="margin:0 0 4px 0;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:'.self::INK_MUTED.'">'
            .'รายละเอียดสินค้า</p>'
            .'<p style="margin:0 0 12px 0;font-size:16px;font-weight:bold;color:'.self::INK.'">'
            .e((string) ($product->effectiveName() ?? '')).'</p>'
            .$sections
            .'</td></tr></table>';
    }

    /**
     * Server-sanitised markup, restyled for mail clients. See productDetails().
     */
    private function richText(?string $html): string
    {
        if (blank($html)) {
            return '';
        }

        $body = 'font-size:14px;line-height:1.75;color:'.self::INK_MUTED.';';

        $replacements = [
            '<p>' => '<p style="margin:0 0 10px 0;'.$body.'">',
            '<ul>' => '<ul style="margin:0 0 10px 0;padding-left:20px;'.$body.'">',
            '<ol>' => '<ol style="margin:0 0 10px 0;padding-left:20px;'.$body.'">',
            '<li>' => '<li style="margin:0 0 4px 0">',
            '<h2>' => '<h2 style="margin:16px 0 6px 0;font-size:15px;color:'.self::INK.'">',
            '<h3>' => '<h3 style="margin:14px 0 6px 0;font-size:14px;color:'.self::INK.'">',
            '<strong>' => '<strong style="color:'.self::INK.'">',
        ];

        $styled = strtr($html, $replacements);

        // <a> carries an href, so it is matched as an opening tag rather than
        // as a literal string. The sanitiser strips every other attribute, so
        // there is never an existing style= to collide with.
        $styled = (string) preg_replace('/<a\b/', '<a style="color:'.self::ACCENT.'"', $styled);

        return '<div style="'.$body.'">'.$styled.'</div>';
    }

    /**
     * The key/value spec sheet, grouped exactly as the product page groups it.
     *
     * Escaped throughout: these are values typed into a form, and unlike the
     * two description fields they have never been near RichText's gate.
     */
    private function specSheet(Product $product): string
    {
        $specs = $product->effectiveSpecs();

        if ($specs->isEmpty()) {
            return '';
        }

        $html = '';

        foreach ($specs->groupBy('spec_group') as $group => $rows) {
            // A group name is optional on the row, so an ungrouped sheet
            // simply renders as one unlabelled block rather than under a
            // heading reading "0" or an empty string.
            if (filled($group)) {
                $html .= '<p style="margin:14px 0 6px 0;font-size:13px;font-weight:bold;color:'.self::INK.'">'
                    .e((string) $group).'</p>';
            }

            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%">';

            foreach ($rows as $row) {
                $html .= '<tr>'
                    .'<td style="padding:5px 12px 5px 0;font-size:13px;color:'.self::INK_MUTED.';vertical-align:top;width:45%">'
                    .e((string) $row->spec_key).'</td>'
                    .'<td style="padding:5px 0;font-size:13px;color:'.self::INK.';vertical-align:top">'
                    .e((string) $row->spec_value).'</td>'
                    .'</tr>';
            }

            $html .= '</table>';
        }

        return $html;
    }

    /**
     * The URL in plain text, under the button.
     *
     * Standard on transactional mail for two reasons that both apply here: a
     * client that strips the button leaves the customer no way to their
     * voucher, and a receipt whose only link is hidden behind button text is
     * exactly the shape of a phishing email — showing the address lets a
     * careful person check the domain before pressing anything.
     *
     * The "no sign-in" line is not reassurance for its own sake: this link
     * goes to the agent portal's domain, and a customer who assumes they need
     * an account they do not have will simply not press it.
     */
    private function linkFallback(string $url): string
    {
        return '<p style="margin:0;font-size:12px;line-height:1.7;color:'.self::INK_MUTED.';word-break:break-all">'
            .'เปิดได้ทันทีโดยไม่ต้องเข้าสู่ระบบ หากปุ่มด้านบนกดไม่ได้ ให้คัดลอกลิงก์นี้ไปวางในเบราว์เซอร์:<br>'
            .'<a href="'.e($url).'" style="color:'.self::INK_MUTED.'">'.e($url).'</a></p>';
    }
}
