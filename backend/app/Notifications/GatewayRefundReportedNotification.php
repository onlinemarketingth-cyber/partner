<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\MailBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The gateway says a sale was refunded. Tell the people who have to act.
 *
 * ── WHY A MAIL AND NOT AN IN-APP NOTIFICATION ──
 *
 * NotificationService writes rows the AGENT PORTAL reads: NotificationLink
 * resolves in-app paths for that app and AgentNotificationEmail links to it.
 * The recipients here are Company Admins, who work in a different app, so a
 * row there would give them a button into a portal they do not use. This
 * follows NewAgentRegistrationNotification instead — the established shape
 * for an admin-facing alert, pointed at the admin console.
 *
 * ── WHY IT IS NOT QUEUED ──
 *
 * Same reason NewAgentRegistrationNotification is not: with
 * QUEUE_CONNECTION=database, a queued notification inserts a `jobs` row and
 * returns, and if no worker is running the mail silently never sends. For an
 * alert whose entire purpose is that somebody finds out, failing silently is
 * the one outcome that must not happen.
 *
 * The cost is one SMTP round-trip per admin inside Stripe's webhook request.
 * Acceptable here specifically because a refund is rare — this is not
 * `checkout.session.completed`, which fires on every sale. If refunds ever
 * become common enough for that latency to threaten the webhook timeout, the
 * fix is a queue with a monitored worker, not a silent one.
 */
class GatewayRefundReportedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Order $order,
        private readonly ?int $amountSatang,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $adminUrl = MailBrand::adminPortalUrl();
        $brand = MailBrand::forUser($notifiable);

        $amount = $this->amountSatang === null
            ? null
            : number_format($this->amountSatang / 100, 2);

        return (new MailMessage)
            ->markdown('notifications::email', [
                'brand' => $brand,
                'brandUrl' => $adminUrl,
            ])
            ->subject('ผู้ให้บริการแจ้งการคืนเงิน: คำสั่งซื้อ '.$this->order->order_number)
            ->greeting('ถึงคุณ '.trim("{$notifiable->first_name} {$notifiable->last_name}"))
            ->line(
                'คำสั่งซื้อ '.$this->order->order_number
                .($amount === null ? '' : ' ถูกคืนเงินจำนวน ฿'.$amount)
                .' ตามที่ผู้ให้บริการรับชำระเงินแจ้งมา'
            )
            // Said plainly, because the opposite assumption is the dangerous
            // one: an admin who believes the system already reversed the sale
            // will not go and reverse it.
            ->line('ระบบยังไม่ได้กลับรายการขายและยังไม่ได้กลับค่าคอมมิชชั่นของตัวแทน — ทั้งสองอย่างต้องให้คนตัดสินใจ')
            ->action('ไปที่หน้าคำสั่งซื้อ / การชำระเงิน', $adminUrl.'/order-payments')
            ->line('อีเมลนี้ส่งอัตโนมัติจากระบบ '.$brand)
            ->salutation('ขอแสดงความนับถือ '.$brand);
    }
}
