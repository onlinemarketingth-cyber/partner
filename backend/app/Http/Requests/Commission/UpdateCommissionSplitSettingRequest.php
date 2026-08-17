<?php

namespace App\Http\Requests\Commission;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// TASK-174 (D2) — Company Admin (own company) / Super Admin only, same
// visibility shape as UpdateTeamVisibilitySettingRequest.
//
// An Agent must never reach this: the flag decides whether an agent can
// direct part of their own commission to a colleague, so letting the party
// it binds flip it would defeat the switch entirely. Reading it IS
// Agent-allowed (see CommissionSplitSettingController::show) — the Agent
// Portal has to know whether to render the split controls at all.
class UpdateCommissionSplitSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::SettingsCommissionSplitUpdate);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only a Super Admin may target a company other than their own.
            // A Company Admin's value here is validated but then DISCARDED —
            // CommissionSplitSettingService::upsert() strips it and the
            // Controller substitutes the caller's own company_id (BR-6).
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            // BR-7 — the whole feature switch, and the only writable field.
            'is_enabled' => ['required', 'boolean'],
        ];
    }
}
