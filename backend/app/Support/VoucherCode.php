<?php

namespace App\Support;

/**
 * 2026-09-10 (human: "Admin ที่ใช้บัตร voucher นั้นต้องใช้วิธี Key
 * ทำให้รหัสสั้นลงไม่เกิน 6 ตัวได้หรือไม่").
 *
 * ── WHY THE ORIGINAL CODE WAS 40 CHARACTERS, AND WHY THAT WAS WRONG ──
 *
 * ADR-033 gave the voucher `Str::random(40)`, the same treatment as
 * `orders.public_token`. That was right for a token nobody types: it lives in
 * a URL and a QR, and length is free.
 *
 * It is the wrong shape for the job this code actually does. Somebody at a
 * counter reads it off a customer's phone and types it — and forty mixed-case
 * characters cannot be typed correctly, cannot be read aloud over a phone, and
 * cannot be written on a form. The QR was carrying the whole feature, and the
 * moment a screen was cracked, a battery was flat, or the code arrived as a
 * screenshot in a chat, there was no way to redeem at all.
 *
 * ── WHY SIX IS SAFE HERE ──
 *
 * Six characters over this alphabet is 32^6 ≈ 1.07 BILLION codes, and guessing
 * one is not a public attack: /vouchers/{code} and /vouchers/redeem both
 * require a signed-in account that has been GRANTED Ability::VoucherRedeem
 * (2026-09-10 — it is no longer implied by being a Company Admin), both are
 * rate-limited per user, a voucher outside the actor's own company answers 404
 * regardless, and every redemption writes an audit row naming who did it.
 *
 * So the threat is not an anonymous internet, it is a colleague with an
 * account trying thousands of codes — which the throttle makes take years and
 * the audit log makes obvious. That is a different bargain from a public URL,
 * and it is the reason the pay-page token stays 40 characters while this one
 * does not.
 *
 * ── THE ALPHABET IS THE OTHER HALF OF THE FIX ──
 *
 * Crockford's base32: the digits and the letters, MINUS I, L, O and U. The
 * first three because a person reading a screen cannot reliably tell them from
 * 1 and 0 — which is how a "wrong code" that is actually a correct code
 * happens — and U because leaving it out is what stops a random six-character
 * string from occasionally spelling something a staff member has to read out
 * loud to a customer.
 *
 * normalize() then accepts what people actually type: lower case, the dash
 * from the printed form, spaces, and O/I/L typed where 0/1 were meant.
 */
final class VoucherCode
{
    /** Crockford base32 — no I, L, O, U. See the class docblock. */
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LENGTH = 6;

    /**
     * How the code is written down: two groups of three.
     *
     * Chunking is not decoration — it is the difference between copying six
     * characters in one glance and losing your place half way. The dash is
     * stripped again by normalize(), so a person may type it or not.
     */
    private const GROUP = 3;

    /** One new code. Uniqueness is the caller's to enforce (it owns the table). */
    public static function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            // random_int, not rand()/Str::random(): this is a credential for a
            // paid entitlement, and a predictable sequence would let anybody
            // who has seen a few codes work out the next ones.
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * What the person meant, from what they typed.
     *
     * Uppercases, drops everything that is not a letter or digit (the dash from
     * the printed card, spaces, an accidental newline from a paste), then maps
     * the three characters this alphabet deliberately does not contain onto the
     * ones they are always mistaken for.
     *
     * NOT lossless, and deliberately so — which is why the lookup tries the RAW
     * input first. A voucher issued before this change has a 40-character
     * mixed-case code that may legitimately contain O, I, l or lower case, and
     * normalising it would turn a valid code into a miss.
     */
    public static function normalize(string $input): string
    {
        $upper = strtoupper(trim($input));
        $alnum = preg_replace('/[^A-Z0-9]/', '', $upper) ?? '';

        return strtr($alnum, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    /**
     * How it is shown: ABC-123.
     *
     * A code that is not short is returned untouched — a legacy 40-character
     * one chopped into threes would be unreadable, and the point of the format
     * is legibility, not decoration.
     */
    public static function format(string $code): string
    {
        if (! self::isShort($code)) {
            return $code;
        }

        return implode('-', str_split($code, self::GROUP));
    }

    /** A code of the current, keyable shape (as opposed to a legacy token). */
    public static function isShort(string $code): bool
    {
        return strlen($code) === self::LENGTH;
    }
}
