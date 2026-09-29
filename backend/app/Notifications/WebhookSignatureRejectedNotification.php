<?php

namespace App\Notifications;

use App\Support\MailBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 2026-09-29 — a payment webhook was refused for a bad signature.
 *
 * Owner asked for the warning on the payment screen AND to the Super Admins.
 * Sent at most once a day per payment account (WebhookHealthService), and to
 * Super Admins only: the fix is on a Super-Admin-only screen.
 *
 * Says both possible causes, because the system cannot tell them apart: a
 * secret that no longer matches the dashboard, or somebody posting forgeries.
 */
class WebhookSignatureRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $providerLabel,
        private readonly ?string $companyName,
        private readonly string $settingsPath,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $adminUrl = MailBrand::adminPortalUrl();
        $account = $this->companyName === null ? 'ระบบชำระเงินกลาง' : 'บริษัท '.$this->companyName;
        $brand = MailBrand::forUser($notifiable);

        return (new MailMessage)
            ->markdown('notifications::email', [
                'brand' => $brand,
                'brandUrl' => $adminUrl,
            ])
            ->error()
            ->subject("webhook ของ {$this->providerLabel} ถูกปฏิเสธ — {$account}")
            ->greeting('ถึงคุณ '.trim("{$notifiable->first_name} {$notifiable->last_name}"))
            ->line("ระบบได้รับ webhook จาก {$this->providerLabel} ของ{$account} แต่ลายเซ็นไม่ตรงกับ webhook secret ที่บันทึกไว้ จึงปฏิเสธไป")
            ->line('ถ้าเป็นของจริง แปลว่าการชำระเงินที่ยืนยันทีหลัง การคืนเงิน และบัตรถูกปฏิเสธ จะไม่ถูกอัปเดตในระบบ')
            ->line('สาเหตุที่พบบ่อย: webhook secret ในระบบไม่ตรงกับใน Dashboard ของผู้ให้บริการ (หรืออาจมีคนส่ง webhook ปลอม)')
            ->action('เปิดหน้าตั้งค่าแล้วกด "ตรวจสอบ webhook"', $adminUrl.$this->settingsPath)
            ->line('อีเมลนี้ส่งอัตโนมัติวันละไม่เกิน 1 ครั้งต่อบัญชี จากระบบ '.$brand)
            ->salutation('ขอแสดงความนับถือ '.$brand);
    }
}
