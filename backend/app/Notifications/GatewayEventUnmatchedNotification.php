<?php

namespace App\Notifications;

use App\Support\MailBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A verified payment event that names no order in this company.
 *
 * ── WHY THIS IS THE MOST SERIOUS ALERT IN THE PAYMENT PATH ──
 *
 * The signature already passed, so this really did come from the company's
 * own gateway account. It says money moved. And the token that ties a charge
 * to an order — written into the charge's metadata when it was created — is
 * missing or names nothing we hold.
 *
 * That combination means a customer has been charged and no order will ever
 * be marked paid: no commission, no voucher, no pipeline stage, and no
 * receipt. Nothing else in the system will notice, because everything
 * downstream keys off an order that was never touched.
 *
 * Until 2026-09-03 this case wrote one Log::info() line — the same level as
 * routine traffic — and stopped there.
 *
 * ── WHY THE MAIL CANNOT NAME THE CUSTOMER ──
 *
 * There is no order, so there is no client, no product and no agent to name.
 * The charge id is the only handle, and it is deliberately included here
 * (unlike in list payloads) precisely because the recipient's next action is
 * to search for it in the provider's dashboard.
 */
class GatewayEventUnmatchedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $providerLabel,
        private readonly ?string $chargeId,
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
            ->error()
            ->subject('ด่วน: มีการชำระเงินที่จับคู่กับคำสั่งซื้อไม่ได้')
            ->greeting('ถึงคุณ '.trim("{$notifiable->first_name} {$notifiable->last_name}"))
            ->line(
                'ระบบได้รับแจ้งการชำระเงินจาก '.$this->providerLabel
                .($amount === null ? '' : ' จำนวน ฿'.$amount)
                .' แต่หาคำสั่งซื้อที่ตรงกันในระบบไม่พบ'
            )
            ->line('แปลว่าลูกค้าถูกตัดเงินไปแล้ว แต่จะไม่มีคำสั่งซื้อใดถูกทำเครื่องหมายว่าชำระแล้ว และค่าคอมมิชชั่นจะไม่ถูกคำนวณให้')
            ->line($this->chargeId === null
                ? 'ผู้ให้บริการไม่ได้ส่งรหัสรายการมาด้วย'
                : 'รหัสรายการสำหรับค้นหาในระบบของผู้ให้บริการ: '.$this->chargeId)
            ->line('กรุณาตรวจสอบกับผู้ให้บริการรับชำระเงิน แล้วยืนยันการชำระเงินให้ลูกค้ารายนี้ด้วยตนเอง')
            ->action('ไปที่หน้าคำสั่งซื้อ / การชำระเงิน', $adminUrl.'/order-payments')
            ->line('อีเมลนี้ส่งอัตโนมัติจากระบบ '.$brand)
            ->salutation('ขอแสดงความนับถือ '.$brand);
    }
}
