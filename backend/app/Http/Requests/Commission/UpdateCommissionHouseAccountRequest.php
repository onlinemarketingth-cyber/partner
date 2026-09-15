<?php

namespace App\Http\Requests\Commission;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 2026-09-15 — the company's own seat: its name, and where its money goes.
 *
 * `display_name` is REQUIRED here where it is optional on store(). Creating
 * the seat with no name means "use the company's name", which is a sensible
 * default. Renaming it to nothing means nothing at all — and because
 * `users.name` is derived from this field, an empty one would leave the seat
 * with a blank label on every payout row it appears on.
 *
 * ── THE BANK FIELDS (ครั้งที่สอง, same day) ──
 *
 * This door was one field wide for exactly one iteration, on the reasoning
 * that the company never receives a transfer. The owner decided otherwise —
 * "ให้เพิ่มทำจ่ายบริษัทให้เลือกได้ด้วย" — so the seat needs an account to be
 * paid into, and this is the only door onto that row: UserPolicy::update and
 * UserService both refuse it as a person, which is still right and is why
 * PUT /users/{id} cannot be used for this.
 *
 * They are `nullable`, matching UpdateBankAccountRequest: a half-filled
 * account is a real intermediate state, and the refusal for an incomplete one
 * belongs at the moment money is about to move (hasCompletePayoutDetails),
 * not here, where it would block saving the first of three fields.
 */
class UpdateCommissionHouseAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::SettingsCommissionPlanUpdate);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            'display_name' => ['required', 'string', 'max:120'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_holder_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'display_name' => 'ชื่อบัญชีบริษัท',
            'bank_name' => 'ธนาคาร',
            'bank_account_number' => 'เลขที่บัญชี',
            'bank_account_holder_name' => 'ชื่อบัญชี',
        ];
    }
}
