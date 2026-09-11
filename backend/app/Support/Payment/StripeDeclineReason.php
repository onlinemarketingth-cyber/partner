<?php

namespace App\Support\Payment;

/**
 * 2026-09-10 (human: "ไปเก็บ status ทั้งหมด และทำให้ตัวรับค่าจาก stripe รองรับ
 * ทุก status และขึ้นเตือนผู้ใช้").
 *
 * Every decline code Stripe documents, turned into one Thai sentence that says
 * WHAT HAPPENED and WHAT TO DO NEXT.
 *
 * Sources, read 2026-09-10: docs.stripe.com/testing (the test cards and their
 * error/decline codes) and docs.stripe.com/declines/codes (the full table,
 * including Stripe's own "next steps" column, which is what the second half of
 * each message below is a translation of).
 *
 * ── WHY A TABLE AND NOT STRIPE'S OWN MESSAGE ──
 *
 * Stripe sends `last_payment_error.message` — "Your card was declined." — in
 * English, written for a US cardholder, and identical for a dozen different
 * causes. This message is read by a Thai customer standing at a payment page
 * deciding what to try next, and by the agent chasing the sale. "Your card was
 * declined" tells neither of them anything they can act on; "ยอดเงินในบัตร
 * ไม่พอ" tells both.
 *
 * ── THE THREE THINGS A CUSTOMER CAN ACTUALLY DO ──
 *
 * Every message ends in exactly one of them, because a refusal with no way
 * forward is just bad news:
 *
 *   • fix what they typed        (wrong number, CVC, expiry, postcode)
 *   • try again / try another    (temporary error, no funds, expired)
 *   • ask their bank             (the issuer refused and only they know why)
 *
 * ── THE CODES THAT MUST NOT BE REPEATED BACK ──
 *
 * `lost_card`, `stolen_card`, `fraudulent`, `merchant_blacklist` and
 * `lost_or_stolen_card` all get the ORDINARY decline wording, on Stripe's own
 * instruction and for a reason worth stating: if the person at the checkout is
 * the thief, telling them the issuer flagged the card is a warning; if they
 * are not — and far more often they are not — it is a shop accusing a customer
 * of theft over what is usually a bank's mistake. The issuer contacts the real
 * cardholder. See CONCEALED below, which the test suite pins.
 */
final class StripeDeclineReason
{
    /** What a customer is told when the issuer will not say why, or when we must not. */
    public const GENERIC = 'บัตรถูกปฏิเสธโดยธนาคารผู้ออกบัตร — กรุณาลองบัตรใบอื่น หรือติดต่อธนาคารของคุณ';

    /**
     * Codes that must be presented exactly as an ordinary decline.
     *
     * Stripe's wording: "Don't report more detailed information to your
     * customer. Instead, present it in the same manner as generic_decline."
     *
     * @var list<string>
     */
    public const CONCEALED = [
        'lost_card',
        'stolen_card',
        'fraudulent',
        'merchant_blacklist',
        'lost_or_stolen_card',
    ];

    /**
     * The sentence for one code.
     *
     * An unknown or missing code answers GENERIC rather than inventing
     * something: Stripe adds codes, and a customer must never be shown a raw
     * identifier because this table has not caught up.
     */
    public static function message(?string $code): string
    {
        return match ($code) {
            // ── Fix what was typed ────────────────────────────────────────
            'incorrect_cvc', 'invalid_cvc' => 'รหัส CVC (เลข 3 ตัวหลังบัตร) ไม่ถูกต้อง — กรุณากรอกใหม่อีกครั้ง',
            'incorrect_number', 'invalid_number' => 'เลขบัตรไม่ถูกต้อง — กรุณาตรวจสอบแล้วกรอกใหม่อีกครั้ง',
            'invalid_expiry_month', 'invalid_expiry_year' => 'วันหมดอายุบัตรไม่ถูกต้อง — กรุณากรอกใหม่อีกครั้ง',
            'incorrect_zip' => 'รหัสไปรษณีย์ที่ผูกกับบัตรไม่ถูกต้อง — กรุณากรอกใหม่อีกครั้ง',
            'incorrect_address' => 'ที่อยู่ที่ผูกกับบัตรไม่ถูกต้อง — กรุณากรอกใหม่อีกครั้ง',
            'invalid_track_data' => 'อ่านข้อมูลบัตรไม่ถูกต้อง — กรุณาลองใหม่ หรือใช้บัตรใบอื่น',

            // ── Try again, or try something else ──────────────────────────
            'expired_card', 'partner_expired_card', 'expired_payment_information' => 'บัตรหมดอายุแล้ว — กรุณาใช้บัตรใบอื่น',
            'insufficient_funds', 'partner_insufficient_funds' => 'ยอดเงินในบัตรไม่พอสำหรับรายการนี้ — กรุณาใช้บัตรใบอื่น หรือชำระด้วยการโอนเงิน',
            'card_velocity_exceeded', 'withdrawal_count_limit_exceeded', 'payment_limit_exceeded' => 'ใช้เกินวงเงินหรือจำนวนครั้งที่ธนาคารกำหนดไว้แล้ว — กรุณาใช้บัตรใบอื่น หรือติดต่อธนาคารของคุณ',
            'processing_error', 'partner_processing_error' => 'เกิดข้อผิดพลาดระหว่างประมวลผล — กรุณาลองใหม่อีกครั้ง',
            'issuer_not_available' => 'ติดต่อธนาคารผู้ออกบัตรไม่ได้ชั่วคราว — กรุณารอสักครู่แล้วลองใหม่อีกครั้ง',
            'approve_with_id', 'reenter_transaction', 'try_again_later' => 'รายการนี้ยังทำไม่สำเร็จ — กรุณาลองใหม่อีกครั้ง หากยังไม่ได้ กรุณาติดต่อธนาคารของคุณ',
            'card_decline_rate_limit_exceeded' => 'ลองชำระเงินถี่เกินไป — กรุณารอสักครู่แล้วลองใหม่อีกครั้ง',

            // ── Only the bank can answer ──────────────────────────────────
            'card_not_supported' => 'บัตรใบนี้ใช้ชำระรายการประเภทนี้ไม่ได้ — กรุณาใช้บัตรใบอื่น หรือติดต่อธนาคารของคุณ',
            'currency_not_supported', 'partner_invalid_currency' => 'บัตรใบนี้ใช้ชำระเป็นเงินบาทไม่ได้ — กรุณาใช้บัตรใบอื่น',
            'invalid_amount', 'partner_invalid_amount' => 'ธนาคารไม่อนุญาตให้ชำระด้วยยอดจำนวนนี้ — กรุณาติดต่อธนาคารของคุณ หรือชำระด้วยการโอนเงิน',

            /*
             * ── The one that stops a customer paying twice ────────────────
             *
             * Stripe's next step for this is "check to see if a recent payment
             * already exists" — advice for us, not for the customer. The
             * customer's version of it is: do not just press pay again.
             */
            'duplicate_transaction', 'partner_duplicate_transaction' => 'มีรายการชำระเงินยอดเดียวกันเข้ามาก่อนหน้านี้ไม่นาน — กรุณาตรวจสอบว่าชำระสำเร็จไปแล้วหรือยัง ก่อนลองใหม่',

            // ── Authentication (3-D Secure / OTP) ─────────────────────────
            'authentication_required',
            'authentication_not_handled',
            'mobile_device_authentication_required',
            'payment_intent_authentication_failure' => 'ธนาคารขอให้ยืนยันตัวตนเพิ่มเติม (OTP / 3-D Secure) — กรุณาลองใหม่และทำตามขั้นตอนของธนาคารให้ครบ',

            /*
             * ── Card reader codes ─────────────────────────────────────────
             *
             * Not reachable through an online checkout — kept because the list
             * is the list, and a PIN prompt appearing as "บัตรถูกปฏิเสธ" the
             * day this platform gains a counter terminal would be a puzzle
             * nobody could solve from the message.
             */
            'incorrect_pin', 'invalid_pin' => 'รหัส PIN ไม่ถูกต้อง — กรุณาลองใหม่อีกครั้ง',
            'offline_pin_required', 'online_or_offline_pin_required' => 'บัตรใบนี้ต้องเสียบบัตรและกดรหัส PIN — กรุณาทำรายการใหม่อีกครั้ง',
            'pin_try_exceeded' => 'ใส่รหัส PIN ผิดเกินจำนวนครั้งที่กำหนด — กรุณาใช้บัตรใบอื่น หรือช่องทางอื่น',

            /*
             * ── Test card used against live keys ──────────────────────────
             *
             * Aimed at the SELLER, not the customer, because the customer
             * cannot be the cause: it means live keys met a Stripe test
             * number.
             */
            'testmode_decline' => 'ระบบกำลังรับชำระเงินจริง แต่ได้รับเลขบัตรทดสอบ — กรุณาใช้บัตรจริง หรือแจ้งผู้ขายให้ตรวจสอบการตั้งค่า',

            /*
             * ── Local payment methods ─────────────────────────────────────
             *
             * Not reachable today — this platform sends `payment_method_types:
             * ['card']` and collects PromptPay through its own QR (see
             * StripeGateway::startPayment). Mapped anyway so that enabling one
             * later is a config change rather than a silent gap.
             */
            'invalid_customer_account', 'invalid_business_account' => 'บัญชีผู้ให้บริการชำระเงินของคุณใช้ชำระรายการนี้ไม่ได้ — กรุณาตรวจสอบบัญชี หรือใช้ช่องทางอื่น',
            'invalid_billing_agreement', 'invalid_authorization' => 'การอนุญาตหักเงินไม่ถูกต้องหรือถูกยกเลิกไปแล้ว — กรุณาตั้งค่าใหม่ หรือใช้ช่องทางอื่น',
            'invalid_payment_information' => 'ข้อมูลการชำระเงินไม่ถูกต้อง — กรุณาตรวจสอบแล้วลองใหม่อีกครั้ง',
            'recurring_not_supported_by_bank' => 'ธนาคารของคุณไม่รองรับการตัดเงินอัตโนมัติสำหรับช่องทางนี้ — กรุณาเลือกธนาคารอื่น หรือใช้ช่องทางอื่น',
            'payment_disputed' => 'รายการนี้อยู่ระหว่างการโต้แย้งกับผู้ให้บริการชำระเงิน — กรุณาติดต่อผู้ขาย',
            'compliance_violation' => 'รายการนี้ไม่ผ่านเงื่อนไขของผู้ให้บริการชำระเงิน — กรุณาใช้ช่องทางอื่น หรือติดต่อผู้ขาย',

            /*
             * ── Everything else, and everything we must not explain ───────
             *
             * generic_decline · do_not_honor · call_issuer · no_action_taken ·
             * not_permitted · transaction_not_allowed · service_not_allowed ·
             * security_violation · stop_payment_order · invalid_account ·
             * new_account_information_available · revocation_of_authorization ·
             * revocation_of_all_authorizations · restricted_card ·
             * pickup_card · do_not_try_again · partner_generic_decline ·
             * partner_high_risk_customer · partner_payment_not_found ·
             * partner_action_not_supported · card_already_activated —
             * every one of these is "the issuer said no and will not say why",
             * plus the four in CONCEALED that we deliberately will not repeat.
             */
            default => self::GENERIC,
        };
    }

    /** Is this a code whose real reason must never be shown? (Pinned by tests.) */
    public static function isConcealed(string $code): bool
    {
        return in_array($code, self::CONCEALED, true);
    }
}
