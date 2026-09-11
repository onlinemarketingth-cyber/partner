<?php

namespace Tests\Unit\Payment;

use App\Support\Payment\StripeDeclineReason;
use PHPUnit\Framework\TestCase;

/**
 * 2026-09-10 (human: "ไปเก็บ status ทั้งหมด และทำให้ตัวรับค่าจาก stripe รองรับ
 * ทุก status และขึ้นเตือนผู้ใช้").
 *
 * The list below is every decline code on docs.stripe.com/declines/codes plus
 * the error codes on docs.stripe.com/testing, copied verbatim on 2026-09-10.
 * It is here rather than in the source because it is EVIDENCE: if Stripe adds
 * a code, this file is the thing that has to be edited to notice.
 *
 * What the tests hold to:
 *
 *  1. every code produces a Thai sentence — never a raw identifier, never
 *     empty, never English;
 *  2. every sentence ends in something the person reading it can DO;
 *  3. the codes Stripe says must not be explained are indistinguishable from
 *     an ordinary decline;
 *  4. an unknown code — the one Stripe adds next year — still says something
 *     sensible rather than leaking a value into a customer's face.
 */
class StripeDeclineReasonTest extends TestCase
{
    /**
     * Every card decline code Stripe documents.
     *
     * @return list<string>
     */
    private function everyCardCode(): array
    {
        return [
            'approve_with_id', 'authentication_not_handled', 'authentication_required',
            'call_issuer', 'card_not_supported', 'card_velocity_exceeded',
            'currency_not_supported', 'do_not_honor', 'do_not_try_again',
            'duplicate_transaction', 'expired_card', 'fraudulent', 'generic_decline',
            'incorrect_address', 'incorrect_cvc', 'incorrect_number', 'incorrect_pin',
            'incorrect_zip', 'insufficient_funds', 'invalid_account', 'invalid_amount',
            'invalid_cvc', 'invalid_expiry_month', 'invalid_expiry_year', 'invalid_number',
            'invalid_pin', 'issuer_not_available', 'lost_card', 'merchant_blacklist',
            'new_account_information_available', 'no_action_taken', 'not_permitted',
            'offline_pin_required', 'online_or_offline_pin_required', 'pickup_card',
            'pin_try_exceeded', 'processing_error', 'reenter_transaction',
            'restricted_card', 'revocation_of_all_authorizations',
            'revocation_of_authorization', 'security_violation', 'service_not_allowed',
            'stolen_card', 'stop_payment_order', 'testmode_decline',
            'transaction_not_allowed', 'try_again_later', 'withdrawal_count_limit_exceeded',
            'mobile_device_authentication_required',
            // From docs.stripe.com/testing rather than the decline table.
            'card_declined', 'card_decline_rate_limit_exceeded',
            'payment_intent_authentication_failure', 'invalid_track_data',
        ];
    }

    /**
     * The local-payment-method table. Not reachable while this platform sends
     * `payment_method_types: ['card']`, and mapped anyway so that turning one
     * on is a config change rather than a silent gap.
     *
     * @return list<string>
     */
    private function everyLocalMethodCode(): array
    {
        return [
            'partner_generic_decline', 'invalid_customer_account', 'payment_limit_exceeded',
            'invalid_billing_agreement', 'partner_expired_card', 'partner_processing_error',
            'partner_insufficient_funds', 'partner_invalid_currency', 'partner_invalid_amount',
            'invalid_business_account', 'partner_high_risk_customer', 'compliance_violation',
            'payment_disputed', 'invalid_authorization', 'invalid_payment_information',
            'partner_payment_not_found', 'expired_payment_information',
            'partner_duplicate_transaction', 'recurring_not_supported_by_bank',
            'partner_action_not_supported', 'lost_or_stolen_card', 'card_already_activated',
            'invalid_track_data',
        ];
    }

    public function test_every_documented_code_answers_in_thai(): void
    {
        foreach ([...$this->everyCardCode(), ...$this->everyLocalMethodCode()] as $code) {
            $message = StripeDeclineReason::message($code);

            $this->assertNotSame('', $message, "no message for {$code}");
            // Thai characters present, and the raw identifier absent: a
            // customer must never be shown "card_velocity_exceeded".
            $this->assertMatchesRegularExpression('/[\x{0E00}-\x{0E7F}]/u', $message, "not Thai for {$code}");
            $this->assertStringNotContainsString($code, $message, "leaked the code for {$code}");
            $this->assertStringNotContainsString('_', $message, "looks like a code, not a sentence: {$code}");
        }
    }

    public function test_every_message_ends_in_something_the_reader_can_do(): void
    {
        /*
         * A refusal with no way forward is just bad news. Every sentence must
         * name one of the three things a person can actually do: fix what they
         * typed, try something else, or ask their bank.
         */
        $actions = ['กรอกใหม่', 'ลองใหม่', 'ใช้บัตรใบอื่น', 'ติดต่อธนาคาร', 'ใช้ช่องทางอื่น',
            'โอนเงิน', 'ติดต่อผู้ขาย', 'ตรวจสอบ', 'เลือกธนาคารอื่น', 'ทำรายการใหม่', 'ใช้บัตรจริง', 'ตั้งค่าใหม่'];

        foreach ([...$this->everyCardCode(), ...$this->everyLocalMethodCode()] as $code) {
            $message = StripeDeclineReason::message($code);

            $found = false;
            foreach ($actions as $action) {
                if (str_contains($message, $action)) {
                    $found = true;
                    break;
                }
            }

            $this->assertTrue($found, "no next step in the message for {$code}: {$message}");
        }
    }

    public function test_the_codes_stripe_says_not_to_explain_look_like_any_other_decline(): void
    {
        /*
         * THE ONE THAT MATTERS. If the person at the checkout is the thief,
         * telling them the issuer flagged the card is a warning; if they are
         * not — and far more often they are not — it is a shop accusing a
         * customer of theft over a bank's mistake.
         */
        foreach (StripeDeclineReason::CONCEALED as $code) {
            $this->assertSame(
                StripeDeclineReason::GENERIC,
                StripeDeclineReason::message($code),
                "{$code} must be indistinguishable from a generic decline",
            );
        }

        // And nothing in that wording hints at the real reason.
        foreach (['หาย', 'ขโมย', 'ทุจริต', 'โกง', 'lost', 'stolen', 'fraud'] as $word) {
            $this->assertStringNotContainsString($word, StripeDeclineReason::GENERIC);
        }
    }

    public function test_the_actionable_codes_say_the_actionable_thing(): void
    {
        // The whole point of the table: these must NOT collapse into the
        // generic wording, or a customer with a full wallet is told to phone
        // their bank about a card that simply expired.
        $this->assertStringContainsString('ยอดเงินในบัตรไม่พอ', StripeDeclineReason::message('insufficient_funds'));
        $this->assertStringContainsString('หมดอายุ', StripeDeclineReason::message('expired_card'));
        $this->assertStringContainsString('CVC', StripeDeclineReason::message('incorrect_cvc'));
        $this->assertStringContainsString('เลขบัตร', StripeDeclineReason::message('incorrect_number'));
        $this->assertStringContainsString('OTP', StripeDeclineReason::message('authentication_required'));
        $this->assertStringContainsString('วงเงิน', StripeDeclineReason::message('card_velocity_exceeded'));
    }

    public function test_a_duplicate_is_a_warning_not_to_pay_twice(): void
    {
        // Stripe's own next step for this one is advice to US — "check whether
        // a recent payment already exists". The customer's version of it is
        // the important half: do not just press pay again.
        $message = StripeDeclineReason::message('duplicate_transaction');

        $this->assertStringContainsString('ชำระสำเร็จไปแล้วหรือยัง', $message);
    }

    public function test_a_code_nobody_has_seen_yet_still_says_something_sensible(): void
    {
        // Stripe adds codes. The failure mode to avoid is a customer being
        // shown a raw identifier because this table has not caught up.
        $this->assertSame(StripeDeclineReason::GENERIC, StripeDeclineReason::message('a_code_from_2027'));
        $this->assertSame(StripeDeclineReason::GENERIC, StripeDeclineReason::message(null));
        $this->assertSame(StripeDeclineReason::GENERIC, StripeDeclineReason::message(''));
    }
}
