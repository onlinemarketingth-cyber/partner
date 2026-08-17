<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TASK-020 (ADR-005 decision 3) — sent to every Company Admin of the
// registrant's company the moment a self-registration becomes
// reviewable (email verified for the email/password channel; immediately
// for the social channel — see AgentReadyForApproval's dispatch sites).
//
// 2026-08-17 — deliberately NOT ShouldQueue (used to implement it, same
// bug class as VerifyRegistrationEmailNotification's own fix): with
// QUEUE_CONNECTION=database and no queue:work process guaranteed running
// in every environment, a queued notify() just inserts a `jobs` row and
// returns — no email ever sends, no visible error. This notification is
// already fired from a live request (the registrant's own POST that
// triggers AgentReadyForApproval), same as VerifyRegistrationEmailNotification,
// so sending synchronously costs one SMTP round-trip per Company Admin on
// that request and removes the queue-worker dependency entirely. Contrast
// with FollowUpReminderNotification, which stays ShouldQueue because
// nothing HTTP-shaped is waiting on it — it fires off Laravel's scheduler
// (routes/console.php), not a controller action.
class NewAgentRegistrationNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly User $registrant)
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
        // Links into frontend-admin (:5179), not the Agent Portal
        // (:5178) — this notification is only ever sent to a Company
        // Admin, who never has an Agent Portal session.
        $adminUrl = rtrim(config('services.company_admin_portal.frontend_url'), '/');

        return (new MailMessage)
            ->subject('มีตัวแทนใหม่รออนุมัติ: '.$this->registrant->name)
            ->greeting('ถึงคุณ '.trim("{$notifiable->first_name} {$notifiable->last_name}"))
            ->line($this->registrant->name.' ('.$this->registrant->email.') ได้สมัครเข้าร่วมบริษัทของคุณผ่านระบบสมัครสมาชิกด้วยตนเอง')
            ->line('กรุณาตรวจสอบและอนุมัติ/ปฏิเสธคำขอนี้ก่อนที่ตัวแทนจะสามารถเข้าใช้งานได้')
            ->action('ไปที่หน้าอนุมัติตัวแทน', $adminUrl.'/agents')
            ->line('อีเมลนี้ส่งอัตโนมัติจากระบบ Sync Vision Agent');
    }
}
