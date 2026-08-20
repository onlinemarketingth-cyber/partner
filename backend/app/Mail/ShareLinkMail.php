<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * TASK-212 — the link an agent shares from <ShareLinkModal>, delivered by
 * the platform's own SMTP instead of a `mailto:` handoff.
 *
 * Carries only a heading, a URL and the sender's name. It is deliberately
 * NOT modelled on a specific resource the way OrderPaymentConfirmedMail is
 * bound to an Order: the same sheet sends payment links, product links and
 * team-invite links, and those have nothing in common except "here is a
 * link somebody wanted you to open". Resolving each type's heading and URL
 * happens once, in ShareLinkEmailService, so this class has no opinion
 * about which of the three it is carrying.
 *
 * `Content::htmlString()` rather than a Blade view, and no `ShouldQueue`,
 * for exactly the reasons OrderPaymentConfirmedMail's docblock gives:
 * CLAUDE.md §3 keeps Blade out of this repo, and queue:work is not
 * guaranteed to be running, so queuing would risk this silently never
 * sending. The agent is watching the modal when they press ส่ง — a
 * synchronous send is what lets the UI tell them the truth.
 */
class ShareLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $heading,
        public readonly string $url,
        public readonly string $senderName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        // e() on every interpolated value: `heading` is derived from
        // admin/agent-entered names (a product name, an invite-link label),
        // so it reaches here as untrusted text, not markup.
        $html = '<p>สวัสดีค่ะ/ครับ</p>'
            .'<p>'.e($this->senderName).' ได้ส่งลิงก์นี้ให้คุณ:</p>'
            .'<p><strong>'.e($this->heading).'</strong></p>'
            .'<p><a href="'.e($this->url).'">'.e($this->url).'</a></p>'
            .'<p>หากคุณไม่ได้คาดหวังอีเมลฉบับนี้ สามารถละเว้นได้</p>';

        return new Content(htmlString: $html);
    }
}
