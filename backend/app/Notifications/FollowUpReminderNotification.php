<?php

namespace App\Notifications;

use App\Models\ClientActivity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// TASK-016 (ADR-004) — sent to the agent who set the follow-up
// (logged_by_user_id), not necessarily the client's current
// referring_agent_id (a Company Admin may have logged the activity on
// an agent's behalf). via() returns only 'mail' for now — a LINE
// channel is appended later once the human supplies LINE OA
// credentials (ADR-004); no other change is needed here or in the
// dispatch command when that happens.
class FollowUpReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ClientActivity $activity)
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
        // There is no per-client detail route in the Agent Portal yet
        // (ClientsView.vue lists all clients and opens a drawer
        // client-side, no /clients/{id} URL) — the link goes to the
        // list, and the client's name is spelled out in the body so the
        // agent knows who to look for.
        $frontendUrl = rtrim(config('services.agent_portal.frontend_url'), '/');

        return (new MailMessage)
            ->subject('ครบกำหนดติดตามลูกค้า: '.$this->activity->client->name)
            ->greeting('ถึงคุณ '.trim("{$notifiable->first_name} {$notifiable->last_name}"))
            ->line('ถึงเวลาติดตามลูกค้า '.$this->activity->client->name.' แล้ว')
            ->line('บันทึกล่าสุด: '.$this->activity->summary)
            ->action('ไปที่หน้าลูกค้า', $frontendUrl.'/clients')
            ->line('อีเมลนี้ส่งอัตโนมัติจากระบบ Sync Vision Agent');
    }
}
