<?php

namespace App\Enums;

/**
 * ADR-050 — WHOSE ACCOUNT A CUSTOMER'S MONEY GOES TO.
 *
 * Owner, 2026-09-29: the platform must be able to take every company's
 * payments through ONE set of channels, chosen by the Super Admin, instead
 * of each company connecting its own (ADR-027). Both stay possible; this is
 * the switch.
 *
 * The same two values answer two questions, deliberately:
 *
 *   platform_payment_settings.mode  which accounts NEW orders are born with.
 *   orders.payment_account          which accounts THIS order pays into,
 *                                   stamped once at creation. A pay link
 *                                   already in a customer's hand keeps the
 *                                   bank account and gateway it showed them,
 *                                   whatever the Super Admin switches later.
 *
 *   Company   each company's own bank account / PromptPay and its own
 *             Omise or Stripe keys (ADR-027, and the default).
 *   Platform  the platform's single bank account / PromptPay and its single
 *             online gateway, for every company. The money lands with the
 *             platform, which settles with each company outside this system.
 */
enum PaymentAccountScope: string
{
    case Company = 'company';
    case Platform = 'platform';

    public static function default(): self
    {
        return self::Company;
    }

    public function label(): string
    {
        return match ($this) {
            self::Company => 'แยกรายบริษัท',
            self::Platform => 'ใช้ค่าเดียวทุกบริษัท',
        };
    }
}
