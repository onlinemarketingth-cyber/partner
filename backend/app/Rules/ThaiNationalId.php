<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * TASK-049 — validates a Thai national ID (เลขบัตรประชาชน): exactly 13
 * digits, with the 13th digit being the standard modulo-11 checksum of
 * the first 12. Digits-only is enforced (no spaces/dashes) so the stored
 * value and its blind-index hash are canonical. Applied on the plaintext
 * BEFORE the model's 'encrypted' cast runs, so validation sees the real
 * number, never ciphertext.
 */
class ThaiNationalId implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $id = (string) $value;

        if (! preg_match('/^\d{13}$/', $id)) {
            $fail('เลขบัตรประชาชนต้องเป็นตัวเลข 13 หลัก');

            return;
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ((int) $id[$i]) * (13 - $i);
        }
        $checkDigit = (11 - ($sum % 11)) % 10;

        if ($checkDigit !== (int) $id[12]) {
            $fail('เลขบัตรประชาชนไม่ถูกต้อง (เลขตรวจสอบไม่ผ่าน)');
        }
    }
}
