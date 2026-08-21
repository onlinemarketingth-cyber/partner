<?php

namespace App\Http\Requests\Registration;

use App\Services\Registration\RegistrationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The signup form asks "is this address already an account?" before the
 * person finishes filling the rest of the form.
 *
 * ── WHY THIS REQUEST IS NOT THIN LIKE ITS NEIGHBOURS ──
 *
 * ResolveInviteCodeRequest and ResolveRefTokenRequest validate shape and
 * nothing else, on purpose. This one deliberately does more, because of what
 * the endpoint behind it IS: an unauthenticated caller naming an address and
 * being told, yes or no, whether that person has an account here.
 *
 * That is an account-enumeration oracle in its plainest form. On a platform
 * where every account is an insurance agent earning commission, the list it
 * would leak is a competitor's recruiting list and a phisher's target list,
 * and it would be free to harvest at machine speed.
 *
 * ── THE INFORMATION IS NOT NEW; THE COST OF HARVESTING IT IS ──
 *
 * POST /register already answers 422 "อีเมลนี้ถูกใช้งานแล้ว" through
 * `unique:users,email`, so the same fact was always obtainable. What that
 * path costs an attacker is a complete, VALID registration payload per
 * guess — a live invite code or recruit token, a checksum-valid Thai
 * national ID that is not already registered, a compliant password. This
 * endpoint would otherwise reduce that to one field.
 *
 * ── SO THE LINK IS THE PRICE OF ADMISSION ──
 *
 * The caller must present the same live invite code or recruit token that
 * RegisterRequest demands, validated by the same RegistrationService
 * lookups, so an enumerator needs a working link before asking the first
 * question — and links are quota-bounded, expiring and revocable, which is
 * exactly the lever a company needs when abuse shows up. A real recruit
 * always holds one: the form is not on screen until it resolved.
 *
 * This costs the genuine user nothing and costs an enumerator the whole
 * advantage, which is the only trade worth making here.
 *
 * The mutual-exclusion and lookup rules mirror RegisterRequest's on purpose
 * — the answer must be gated by exactly what the submit is gated by, or the
 * check would tell somebody "available" down a path that then refuses them.
 */
class CheckEmailRequest extends FormRequest
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
            // `email` only — NOT `unique:users,email`. The answer is this
            // endpoint's response body, not a validation error: a 422 here
            // would make the form show a red field message about a rule the
            // person has not broken yet, on an address they are still typing.
            'email' => ['required', 'string', 'email', 'max:255'],

            'invite_code' => [
                'bail',
                'required_without:ref_token',
                Rule::prohibitedIf(fn () => $this->filled('ref_token')),
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! app(RegistrationService::class)->resolveInviteCode($value)) {
                        $fail('รหัสเชิญไม่ถูกต้องหรือหมดอายุแล้ว');
                    }
                },
            ],

            'ref_token' => [
                'bail',
                'required_without:invite_code',
                Rule::prohibitedIf(fn () => $this->filled('invite_code')),
                'string',
                // max, not size — see ResolveRefTokenRequest's docblock.
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! app(RegistrationService::class)->resolveRefToken($value)) {
                        $fail('ลิงก์ชวนเข้าทีมนี้ไม่ถูกต้อง หมดอายุ ถูกยกเลิก หรือมีผู้ใช้ครบจำนวนแล้ว');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $eitherOr = 'ต้องระบุรหัสเชิญ หรือมาจากลิงก์ชวนเข้าทีมอย่างใดอย่างหนึ่ง';

        return [
            'email.required' => 'กรุณากรอกอีเมล',
            'email.email' => 'รูปแบบอีเมลไม่ถูกต้อง',
            'invite_code.required_without' => $eitherOr,
            'ref_token.required_without' => $eitherOr,
            'invite_code.prohibited' => $eitherOr,
            'ref_token.prohibited' => $eitherOr,
        ];
    }
}
