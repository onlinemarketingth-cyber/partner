<?php

namespace App\Http\Requests\Registration;

use App\Enums\IdDocumentType;
use App\Rules\IdDocument;
use App\Services\Registration\RegistrationService;
use App\Support\PasswordRuleMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

// ADR-005 — public, unauthenticated. invite_code validity is checked
// right here (inline rule) so an invalid/expired/revoked code surfaces
// as a normal per-field 422 like every other Form Request in this
// codebase, rather than a Service-level abort — RegistrationService
// still re-checks it defensively (time may pass between validation and
// the DB write).
//
// TASK-114 / ADR-025 §5 — a recruit may instead arrive with `ref_token`,
// a team leader's recruit link. The two are MUTUALLY EXCLUSIVE: exactly
// one of them must be present. A link already carries company_id from its
// inviter, so stacking a code on top of it would create a second, silent
// source of truth for which tenant the new user joins.
//
// WHAT IS NOT IN THIS FILE IS AS IMPORTANT AS WHAT IS: there is no rule
// for `company_id`, `manager_id`, `agent_approval_status` or
// `recruited_via_agent_link_id`. validated() therefore drops them, and
// the Service — which only ever reads validated() — cannot honour them
// even if a client sends them (BR-6 / §5 rule 5). Do not add them "for
// convenience".
//
// TASK-122 — `id_document_type` + `national_id` ARE new rules here, and
// unlike the four above they are meant to reach the Service: they describe
// the PERSON, not the tenant they land in, so they belong with
// first_name/email/phone. Both are REQUIRED, on BOTH self-registration
// paths (invite code and recruit link).
//
// WHY BOTH PATHS, when the human only asked about the link: two public
// registration endpoints that disagree about what identity is required is
// not a feature, it is a hole — anyone who wanted to skip the document
// would simply register with an invite code instead, and we would have
// spent the effort for nothing. If a path is ever meant to be exempt, that
// has to be a stated business decision (BR-7), not an omission.
//
// The DUPLICATE check for the document deliberately does NOT live here —
// it is per-company (see RegistrationService), and this Request has no idea
// which company the caller will land in; that is derived server-side from
// the invite code or the recruit link, after validation.
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'invite_code' => [
                // `bail` so a request carrying BOTH credentials fails on the
                // mutual-exclusion rule alone, instead of also running the
                // lookup closure below and returning two confusing errors
                // for one mistake.
                'bail',
                // Neither supplied => both fields report "one is required".
                'required_without:ref_token',
                // Both supplied => both fields report the conflict. Same
                // Rule::prohibitedIf shape StoreCommissionRuleRequest already
                // uses for product_id vs product_category_id.
                Rule::prohibitedIf(fn () => $this->filled('ref_token')),
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! app(RegistrationService::class)->resolveInviteCode($value)) {
                        // Deliberately the same generic message
                        // regardless of whether the code never
                        // existed, expired, or was revoked (ADR-005 —
                        // never leak which reason applied).
                        $fail('รหัสเชิญไม่ถูกต้องหรือหมดอายุแล้ว');
                    }
                },
            ],
            'ref_token' => [
                'bail',
                'required_without:invite_code',
                Rule::prohibitedIf(fn () => $this->filled('invite_code')),
                'string',
                // max, not size — see ResolveRefTokenRequest's docblock for
                // why the exact token length is not validated.
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    // One lookup helper shared with the public resolver, so
                    // "usable" cannot mean two things. Covers the link's own
                    // state (revoked/expired/quota) AND the inviter's
                    // (soft-deleted / de-flagged / moved company) — see
                    // RegistrationService::resolveActiveInviter() for why
                    // that second half is not inside isUsable().
                    //
                    // This is a COURTESY check only. The authoritative one
                    // runs inside the transaction, under lockForUpdate(),
                    // in RegistrationService::registerViaRecruitLink().
                    if (! app(RegistrationService::class)->resolveRefToken($value)) {
                        $fail('ลิงก์ชวนเข้าทีมนี้ไม่ถูกต้อง หมดอายุ ถูกยกเลิก หรือมีผู้ใช้ครบจำนวนแล้ว');
                    }
                },
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            // TASK-122 — WHICH document, then the document itself. The type
            // must be validated first because App\Rules\IdDocument below
            // reads it to decide which check to run (and passes silently
            // when it is missing/unknown, precisely so this field owns that
            // error — see the rule's docblock).
            'id_document_type' => ['required', Rule::enum(IdDocumentType::class)],
            // `max:255` mirrors the column's practical ceiling; the real
            // shape check is the type-aware rule. Not `unique:users,...`:
            // the column is encrypted (so a DB unique index is useless
            // against it) and uniqueness is per-COMPANY, which a Form
            // Request cannot express here — RegistrationService owns it.
            'national_id' => [
                'required',
                'string',
                'max:255',
                new IdDocument($this->input('id_document_type')),
            ],
            // Laravel's built-in defaults (min 8, mixed case, numbers) —
            // a config value, not a hardcoded custom policy; flag if a
            // stricter/different rule is wanted (TASK-018 design note).
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * Laravel's stock wording for these two rules ("The invite code field
     * is required when ref token is not present." / "The invite code field
     * prohibits ref token from being present.") reads as nonsense to a
     * recruit filling in a signup form, so both are replaced with the
     * plain-language explanation TASK-116 will show verbatim.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $eitherOr = 'ต้องระบุรหัสเชิญ หรือมาจากลิงก์ชวนเข้าทีมอย่างใดอย่างหนึ่ง';
        $notBoth = 'ใช้รหัสเชิญและลิงก์ชวนเข้าทีมพร้อมกันไม่ได้ — เลือกอย่างใดอย่างหนึ่ง';

        return [
            ...PasswordRuleMessages::all(),
            'invite_code.required_without' => $eitherOr,
            'ref_token.required_without' => $eitherOr,
            'invite_code.prohibited' => $notBoth,
            'ref_token.prohibited' => $notBoth,
            // TASK-122 — the two cases a real person actually hits: they
            // left the selector alone, or they left the number blank.
            //
            // No override for the "not one of the two values" case on
            // purpose: a custom message for a RULE OBJECT (Rule::enum) has
            // to be keyed by the rule's fully-qualified class name, not by
            // 'id_document_type.enum' — a key of that shape would look right
            // and silently never fire. The stock wording is acceptable for a
            // value the UI can only produce from a fixed two-item control.
            'id_document_type.required' => 'กรุณาเลือกประเภทเอกสารยืนยันตัวตน (บัตรประชาชน หรือ หนังสือเดินทาง)',
            'national_id.required' => 'กรุณากรอกเลขที่บัตรประชาชน หรือเลขที่หนังสือเดินทาง',
            'first_name.required' => 'กรุณากรอกชื่อ',
            'last_name.required' => 'กรุณากรอกนามสกุล',
            'email.required' => 'กรุณากรอกอีเมล',
            'email.email' => 'รูปแบบอีเมลไม่ถูกต้อง',
            // Laravel's stock wording here is "The email has already been
            // taken." — English, on a Thai form, and phrased as a complaint
            // rather than a next step. The usual cause is that the person
            // already signed up, so this points at the login page, the same
            // way the live check on the form does.
            'email.unique' => 'อีเมลนี้มีบัญชีในระบบแล้ว กรุณาเข้าสู่ระบบ หรือใช้อีเมลอื่น',
        ];
    }
}
