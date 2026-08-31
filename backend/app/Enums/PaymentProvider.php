<?php

namespace App\Enums;

/**
 * Who takes the money for a company.
 *
 * Exactly ONE is active per company at a time (human decision, 2026-08-22:
 * "ไม่อนุญาตให้เปิดหลาย gateway เจ้าใดเจ้าหนึ่งเท่านั้น"), held in
 * `companies.payment_provider`.
 *
 * ── `Manual` IS A PROVIDER, NOT AN ABSENCE ──
 *
 * It is what every company does today: PromptPayService builds an EMVCo QR
 * locally, the customer transfers bank-to-bank and uploads a slip, and a
 * person presses confirm. Money moves; nobody's API is involved.
 *
 * Modelling it as a provider rather than as "no gateway configured" is what
 * makes this abstraction describe something real instead of speculative — there are
 * two working examples on day one, not one implementation and a guess about
 * the second. It also means "how does this company get paid" has a single
 * answer everywhere, and the pay page, the admin screen and the reports all
 * read it the same way.
 *
 * ── ADDING A PROVIDER ──
 *
 * A case here, a driver class, and one line in config/payments.php. Nothing
 * in the controllers, the pay page or the settings UI changes. Deliberately
 * NOT pre-populated with 2C2P / GBPrimePay / Chillpay: an enum case with no
 * driver behind it is a choice an admin can pick and then discover does
 * nothing.
 */
enum PaymentProvider: string
{
    case Manual = 'manual';
    case Omise = 'omise';
    case Stripe = 'stripe';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'โอนเงิน / PromptPay (ตรวจสลิปเอง)',
            self::Omise => 'Omise (Opn Payments)',
            self::Stripe => 'Stripe (บัตรเครดิต / PromptPay)',
        };
    }

    /**
     * Does a person have to look at a slip before this counts as paid?
     *
     * The one behavioural difference that everything else in the app cares
     * about. Read this rather than comparing to `Manual` directly, so a
     * second manual-style provider does not need every call site edited.
     */
    public function requiresHumanVerification(): bool
    {
        return $this === self::Manual;
    }
}
