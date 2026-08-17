<?php

namespace App\Rules;

use App\Enums\IdDocumentType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * TASK-122 — validates an identity document number AGAINST ITS DECLARED TYPE.
 *
 *   * Thai national ID -> delegates to the existing App\Rules\ThaiNationalId
 *     (13 digits + modulo-11 checksum). DELEGATED, NOT COPIED: the checksum
 *     algorithm must have exactly one implementation, and ThaiNationalId is
 *     still used on its own by Client (StoreClientRequest /
 *     UpdateClientRequest), which this task does not touch.
 *   * Passport -> 6-12 alphanumeric characters. Deliberately loose: ICAO
 *     Doc 9303 caps the machine-readable passport number field at 9
 *     characters, but many states issue shorter ones and a few print longer
 *     numbers in the visual zone, so a stricter rule would reject real
 *     documents. There is no checksum to verify (the MRZ check digit is not
 *     part of the number a person reads off their passport), so this is a
 *     SHAPE check only — it can tell a typo from a plausible number, it
 *     cannot tell a real passport from an invented one.
 *
 * Applied on the plaintext BEFORE the model's 'encrypted' cast runs, so
 * validation always sees the real number and never ciphertext.
 *
 * WHEN THE TYPE IS MISSING OR UNKNOWN this rule PASSES SILENTLY. That is
 * intentional and not a hole: every Form Request that uses this rule also
 * validates `id_document_type` itself (required / required_with + enum), so
 * an absent or bogus type is already a 422 keyed on that field. Failing here
 * too would give a caller two error messages for one mistake, and the second
 * one ("this number is not a valid X") would be nonsense when X is unknown.
 */
class IdDocument implements ValidationRule
{
    public function __construct(private readonly mixed $rawType)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $type = is_string($this->rawType) ? IdDocumentType::tryFrom($this->rawType) : null;

        if ($type === null) {
            return; // See the class docblock — `id_document_type` owns this error.
        }

        $document = trim((string) $value);

        if ($type === IdDocumentType::ThaiNationalId) {
            (new ThaiNationalId)->validate($attribute, $document, $fail);

            return;
        }

        // Letters allowed in either case here; User::hashNationalId()
        // upper-cases before hashing, so "aa123456" and "AA123456" are the
        // same document for duplicate-detection and search purposes.
        if (! preg_match('/^[A-Za-z0-9]{6,12}$/', $document)) {
            $fail('เลขหนังสือเดินทางต้องเป็นตัวอักษรภาษาอังกฤษหรือตัวเลข 6-12 ตัว');
        }
    }
}
