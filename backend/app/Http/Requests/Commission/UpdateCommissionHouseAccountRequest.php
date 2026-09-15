<?php

namespace App\Http\Requests\Commission;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 2026-09-15 — renaming the company's own seat, and nothing else.
 *
 * `display_name` is REQUIRED here where it is optional on store(). Creating
 * the seat with no name means "use the company's name", which is a sensible
 * default. Renaming it to nothing means nothing at all — and because
 * `users.name` is derived from this field, an empty one would leave the seat
 * with a blank label on every payout row it appears on.
 *
 * There is deliberately no second field. See
 * CommissionHouseAccountService::rename() for why this door is one field
 * wide: every other edit of that row is refused on purpose.
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['display_name' => 'ชื่อบัญชีบริษัท'];
    }
}
