<?php

namespace App\Http\Requests\Concerns;

use App\Support\RichText;
use Closure;

/**
 * 2026-09-09 — the two halves of accepting rich text on a Form Request.
 *
 * Every field that gained a formatting toolbar goes through both:
 *
 *   sanitizeRichText() in prepareForValidation() — the value is cleaned
 *   BEFORE any rule sees it, so validation runs against what will actually be
 *   stored rather than against what arrived. The database can therefore never
 *   hold markup this application did not choose to allow (see RichText).
 *
 *   richTextRules() — the length cap, measured on the WORDS rather than on
 *   the markup.
 */
trait HandlesRichText
{
    /**
     * Clean the named fields in place. Call from prepareForValidation().
     *
     * `has()` rather than `filled()`: an explicit empty string is how an
     * editor says "I cleared this", and that must reach the rules (and then
     * the column) as NULL rather than being skipped and leaving the old value
     * behind.
     *
     * @param  list<string>  $fields
     */
    protected function sanitizeRichText(array $fields): void
    {
        $clean = [];

        foreach ($fields as $field) {
            if ($this->has($field)) {
                $clean[$field] = RichText::sanitize($this->input($field));
            }
        }

        if ($clean !== []) {
            $this->merge($clean);
        }
    }

    /**
     * The rules for one rich-text field.
     *
     * ── WHY THE CAP IS ON PLAIN TEXT ──
     *
     * `max:5000` on the raw value counts the markup: the same paragraph gains
     * roughly seven characters the moment somebody bullets it, so a limit
     * measured on HTML would start refusing text it accepted the day before,
     * for a reason the person cannot see. The cap the admin should feel is the
     * one on what they wrote.
     *
     * ── AND WHY THERE IS A CAP AT ALL, WHERE THERE WAS NONE ──
     *
     * `products.description` and its siblings are MySQL `text` — 65,535 BYTES,
     * which is only about 21,000 Thai characters since Thai is three bytes
     * each. Until today these fields were typed by hand and nothing came close.
     * A formatting editor invites PASTING, and a paste from a Word document
     * carries far more than it looks like. Past the column's ceiling MySQL
     * does not warn, it errors — a 500 on save, with the admin's work still in
     * the browser and no explanation. The cap turns that into a sentence.
     *
     * @return list<mixed>
     */
    protected function richTextRules(int $maxPlainCharacters, string $presence = 'nullable'): array
    {
        return [
            $presence,
            'string',
            function (string $attribute, mixed $value, Closure $fail) use ($maxPlainCharacters) {
                $length = mb_strlen(RichText::toPlainText(is_string($value) ? $value : null));

                if ($length > $maxPlainCharacters) {
                    $fail("ข้อความยาว {$length} ตัวอักษร เกินที่กำหนดไว้ {$maxPlainCharacters} ตัวอักษร");
                }
            },
        ];
    }
}
