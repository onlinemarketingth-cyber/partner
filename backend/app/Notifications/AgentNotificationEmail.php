<?php

namespace App\Notifications;

use App\Models\Notification as NotificationRow;
use App\Support\NotificationLink;
use Illuminate\Bus\Queueable;
use App\Support\MailBrand;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email form of an in-app notification row.
 *
 * ── DELIBERATELY NOT ShouldQueue ──
 *
 * Same reasoning as NewAgentRegistrationNotification's 2026-08-17 fix, and it
 * is the whole delivery design: with QUEUE_CONNECTION=database and no
 * guaranteed `queue:work`, a queued notification inserts a `jobs` row and
 * returns. No mail, no error, nothing to look at. Every path that reaches
 * this class either sends now or records why it could not —
 * NotificationMailer owns that, and the caller decides WHEN (inline after
 * commit for single-recipient events, the sweep command for announcements).
 *
 * ── ONE CLASS FOR EVERY TYPE ──
 *
 * The row already carries a human title and body written by the producer, in
 * Thai, for this exact recipient. A Mailable per NotificationType would mean
 * nine near-identical templates that must be kept in step with nine title
 * strings — and the first one anybody forgot would send a blank email. The
 * only per-type difference that matters is the destination, which
 * NotificationLink already answers for both the SPA and here.
 */
class AgentNotificationEmail extends Notification
{
    use Queueable;

    public function __construct(private readonly NotificationRow $row) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim((string) config('services.agent_portal.frontend_url'), '/');
        $path = NotificationLink::for($this->row);

        $brand = MailBrand::forUser($notifiable);

        $mail = (new MailMessage)
            ->markdown('notifications::email', [
                'brand' => $brand,
                'brandUrl' => $frontendUrl,
            ])
            ->subject($this->row->title)
            ->greeting('ถึงคุณ '.trim("{$notifiable->first_name} {$notifiable->last_name}"))
            ->line($this->row->title);

        // Most producers write a body; a few (announcements) carry the whole
        // message in the title alone. An empty ->line() renders as a stray
        // blank paragraph, so it is skipped rather than sent empty.
        if (filled($this->row->body)) {
            $mail->line($this->row->body);
        }

        // A notification with no destination still deserves an email — the
        // account-status ones are exactly the mails an agent most wants — it
        // just gets no button rather than a button to nowhere. Same rule the
        // SPA follows when NotificationLink answers null.
        if ($path !== null) {
            $mail->action('เปิดในระบบ', $frontendUrl.$path);
        }

        return $mail
            ->line('คุณสามารถปิดการแจ้งเตือนทางอีเมลได้ที่หน้าโปรไฟล์ของคุณ')
            ->line('อีเมลนี้ส่งอัตโนมัติจากระบบ '.$brand)
            // 2026-09-02 — Laravel's default salutation is "Regards," + app name,
            // in English, at the bottom of a Thai email. Set explicitly.
            ->salutation('ขอแสดงความนับถือ '.$brand);
    }
}
