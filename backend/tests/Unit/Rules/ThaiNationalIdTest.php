<?php

namespace Tests\Unit\Rules;

use App\Rules\ThaiNationalId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The mod-11 check digit, pinned directly.
 *
 * ── WHY THIS EXISTS ON TOP OF IdDocumentRegistrationTest ──
 *
 * That test proves the rule is WIRED IN (one good number registers, one bad
 * one 422s). It does not exercise the arithmetic, and the arithmetic has one
 * branch that is wrong in a great many implementations of this algorithm:
 *
 *     $checkDigit = (11 - ($sum % 11)) % 10;
 *                                       ^^^^
 *
 * When the weighted sum divides evenly by 11, `11 - 0` is 11, and the outer
 * `% 10` is the only thing turning that into the 1 the card actually prints.
 * Drop it — or "simplify" it to `% 11` — and every ID whose sum lands on a
 * multiple of 11 is rejected. That is roughly one Thai citizen in eleven,
 * they are spread evenly through the population, and they would each be told
 * their own ID card is invalid with no way to argue. Nobody else would ever
 * see it. `1234567890121` below is exactly that case and is here for it.
 *
 * ── WHY THE FORMAT CASES ARE HERE TOO ──
 *
 * digits-only is not cosmetic. The value is stored encrypted alongside a
 * blind index (User::national_id_hash) used for per-company duplicate
 * detection, so "1-2345-67890-12-1" and "1234567890121" would hash to two
 * different values and the same person could register twice. The rule is the
 * only thing keeping the stored form canonical — RegisterView.vue strips
 * separators as a courtesy, but the Admin API reaches the same column
 * without ever passing through that form.
 *
 * Reported 2026-08-21 as "validate บัตรประชาชนไม่ผ่าน" against the test
 * number 1234567890123 — which genuinely fails: its check digit is 1, not 3.
 * The rule was correct; the test data was not. These cases are what makes
 * that answer checkable rather than a claim.
 */
class ThaiNationalIdTest extends TestCase
{
    /**
     * @return list<string> the messages the rule produced, empty if it passed
     */
    private function failuresFor(string $value): array
    {
        $messages = [];

        (new ThaiNationalId)->validate('national_id', $value, function (string $message) use (&$messages) {
            $messages[] = $message;
        });

        return $messages;
    }

    public function test_a_valid_national_id_passes(): void
    {
        $this->assertSame([], $this->failuresFor('1101700230708'));
    }

    public function test_a_check_digit_of_one_passes_when_the_weighted_sum_divides_by_eleven(): void
    {
        // 1234567890121 — weighted sum 352, which is 32 × 11. Without the
        // outer % 10 the rule computes 11 and rejects a valid card.
        $this->assertSame([], $this->failuresFor('1234567890121'));
    }

    public function test_a_wrong_check_digit_is_rejected(): void
    {
        // Same twelve digits as above with the 3 the reporter typed.
        $failures = $this->failuresFor('1234567890123');

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('เลขตรวจสอบ', $failures[0]);
    }

    public function test_the_checksum_message_is_not_the_length_message(): void
    {
        // Two different problems must read as two different problems.
        // Someone told "must be 13 digits" about a 13-digit number has no
        // idea what to do next.
        $this->assertStringContainsString('13 หลัก', $this->failuresFor('110170023070')[0]);
        $this->assertStringNotContainsString('13 หลัก', $this->failuresFor('1101700230700')[0]);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function nonCanonicalProvider(): array
    {
        return [
            'dashes as printed on the card' => ['1-1017-00230-70-8'],
            'spaces as printed on the card' => ['1 1017 00230 70 8'],
            'twelve digits' => ['110170023070'],
            'fourteen digits' => ['11017002307080'],
            'letters' => ['abcdefghijklm'],
            'empty' => [''],
        ];
    }

    #[DataProvider('nonCanonicalProvider')]
    public function test_anything_that_is_not_thirteen_bare_digits_is_rejected(string $value): void
    {
        // Including the two card formats: they are rejected HERE on purpose,
        // because the stored value feeds a blind index and must be canonical.
        // Making them work is the form's job, not the rule's.
        $this->assertNotSame([], $this->failuresFor($value));
    }
}
