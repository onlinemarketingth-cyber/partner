<?php

namespace App\Enums;

// TASK-122 (human-requested 2026-08-05: "link สมัคร
// ให้บังคับกรอกเลขที่บัตรประชาชน หรือหนังสือเดินทางเลยครับ") — WHICH kind of
// identity document the number in `users.national_id` actually is.
//
// The column keeps its historical name (see the model + migration comments):
// renaming it would ripple through the blind index, the mask, the audit log,
// the Admin UI and the /users search endpoint for no functional gain. This
// enum is what makes the column self-describing instead.
//
// It is NOT a free-text field and must never become one: the value decides
// which validation rule runs (App\Rules\IdDocument) AND which canonical form
// the blind index is derived from (User::hashNationalId) — two passports that
// differ only in their letters must hash differently, which is impossible
// without knowing the type.
enum IdDocumentType: string
{
    case ThaiNationalId = 'thai_national_id';
    case Passport = 'passport';

    public function label(): string
    {
        return match ($this) {
            self::ThaiNationalId => 'บัตรประชาชน',
            self::Passport => 'หนังสือเดินทาง',
        };
    }
}
