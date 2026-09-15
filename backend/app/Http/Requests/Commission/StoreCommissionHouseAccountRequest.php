<?php

namespace App\Http\Requests\Commission;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 2026-09-15 — the input half of "ให้บริษัทรับค่าคอมหัวหน้าทีม".
 *
 * Almost nothing to validate, which is the point: the seat's identity is
 * derived server-side (company, role, a reserved address that cannot receive
 * mail) precisely so a client cannot choose any of it. The one field is the
 * label the company wants on its own row, and it is optional — blank means
 * "use the company's name", which is what an admin means nine times in ten.
 *
 * Gated on SettingsCommissionPlanUpdate, the same ability as writing a rate:
 * switching this on changes what every agent in the company takes home on
 * their next sale, which is a bigger change than most rate edits.
 */
class StoreCommissionHouseAccountRequest extends FormRequest
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
            // Required of a Super Admin for the same reason it always is:
            // they have no company of their own to infer from, and guessing
            // would configure the wrong tenant's payouts (BR-6).
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
