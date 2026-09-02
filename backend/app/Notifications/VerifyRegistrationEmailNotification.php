<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

// ADR-005/TASK-018 — replaces Laravel's stock VerifyEmail notification
// (via User::sendEmailVerificationNotification() override) because the
// stock one links to a route this SPA doesn't render. The email link
// instead points at the Agent Portal frontend's own /verify-email page,
// carrying the exact same signed-URL query params (expires + signature)
// the backend's `signed` route middleware validates — see
// RegisterController::verifyEmail()'s own comment for why this works
// regardless of which domain the person actually clicks through.
//
// 2026-08-17 bugfix — deliberately NOT ShouldQueue (used to implement it).
// QUEUE_CONNECTION=database with no queue:work process running (the
// common local/dev state) meant $user->notify(...) just inserted a row
// into `jobs` and returned — no email was ever sent, no visible error,
// so "resend verification email" silently did nothing. Same lesson
// OrderPaymentConfirmedMail's own docblock already documents (TASK-190):
// queue:work isn't guaranteed running in every environment, so anything
// whose delivery the user is actively waiting on sends synchronously.
class VerifyRegistrationEmailNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly User $user)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            // config('app.name'), not a literal: the mail template's own header
            // already renders it, and a subject naming a DIFFERENT product than
            // the header is how a recruit learns the mail is not from the
            // company they just signed up with.
            ->subject('ยืนยันอีเมลของคุณ - '.config('app.name'))
            ->greeting('สวัสดีคุณ '.trim("{$this->user->first_name} {$this->user->last_name}"))
            // 2026-09-02 — "สมัครสมาชิก Agent" said the role twice, once in
            // each language, and "Agent" is the word the agent portal stopped
            // using when ตัวแทน became สมาชิก. A recruit reading this has not
            // met the word yet and does not need it: they are signing up.
            ->line('กรุณายืนยันอีเมลของคุณเพื่อดำเนินการสมัครสมาชิกต่อ')
            ->action('ยืนยันอีเมล', $this->verificationUrl())
            ->line('ลิงก์นี้จะหมดอายุใน 60 นาที')
            // 2026-09-02 — Laravel's default salutation is "Regards," + app name,
            // in English, at the bottom of a Thai email. Set explicitly.
            ->salutation('ขอแสดงความนับถือ '.config('app.name'));
    }

    private function verificationUrl(): string
    {
        $hash = sha1($this->user->getEmailForVerification());

        $backendSignedUrl = URL::temporarySignedRoute(
            'registration.verify-email',
            now()->addMinutes(60),
            ['id' => $this->user->getKey(), 'hash' => $hash],
        );

        $query = parse_url($backendSignedUrl, PHP_URL_QUERY);
        $frontendUrl = rtrim(config('services.agent_portal.frontend_url'), '/');

        return "{$frontendUrl}/verify-email/{$this->user->getKey()}/{$hash}?{$query}";
    }
}
