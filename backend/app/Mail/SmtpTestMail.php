<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * TASK-201 — the test email sent by PlatformMailSettingService::sendTest()
 * when an admin clicks "ทดสอบส่งอีเมล" on the SMTP settings screen.
 *
 * Constructor takes plain `from_name`/`from_address` strings rather than
 * the whole PlatformMailSetting model — this Mailable exists purely to
 * echo back which config sent it so the admin can visually confirm, and
 * keeping the encrypted `password` attribute off this object entirely
 * (rather than trusting nothing downstream ever touches it) is the
 * simpler guarantee that it can never end up serialized/logged anywhere
 * this class is passed around.
 *
 * DELIBERATELY NOT ShouldQueue — same reasoning as OrderPaymentConfirmedMail
 * (TASK-190 §4.4/ADR-004) and the VerifyRegistrationEmailNotification fix:
 * the admin is actively watching for the result in the UI, so queuing this
 * risks "nothing happens, no error" if no queue:work worker is running.
 * `Queueable` is still `use`d because it's on the framework's own Mailable
 * stub regardless of whether ShouldQueue is implemented — nothing here
 * opts into a queue.
 *
 * DELIBERATELY built with Content::htmlString() rather than a Blade view —
 * same CLAUDE.md §3 "no Blade in this repo" reasoning as
 * OrderPaymentConfirmedMail's own docblock.
 */
class SmtpTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ?string $fromName,
        public readonly ?string $fromAddress,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ทดสอบการตั้งค่า SMTP - '.config('app.name'),
        );
    }

    public function content(): Content
    {
        $html = '<p>นี่คืออีเมลทดสอบจากระบบ '.e(config('app.name')).'</p>'
            .'<p>หากคุณได้รับอีเมลฉบับนี้ แสดงว่าการตั้งค่า SMTP ของระบบใช้งานได้ถูกต้อง</p>'
            .'<p>ส่งจาก: <strong>'.e($this->fromName ?? '-').'</strong> &lt;'.e($this->fromAddress ?? '-').'&gt;</p>';

        return new Content(htmlString: $html);
    }
}
