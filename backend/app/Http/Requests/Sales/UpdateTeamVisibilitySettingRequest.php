<?php

namespace App\Http\Requests\Sales;

use App\Enums\Ability;
use App\Enums\TeamVisibilityLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// TASK-106 / ADR-024 §5 — Company Admin (own company) / Super Admin only,
// same visibility shape as UpdateVideoProcessingSettingRequest.
//
// An Agent must never reach this: a team leader is still role = 'agent'
// (ADR-024 §1), so letting an Agent write here would let a leader widen
// their own view of their team's PDPA-sensitive data.
class UpdateTeamVisibilitySettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::SettingsTeamVisibilityUpdate);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only a Super Admin may target a company other than their own.
            // A Company Admin's value here is validated but then DISCARDED —
            // TeamVisibilitySettingService::upsert() strips it and the
            // Controller substitutes the caller's own company_id (BR-6).
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            // BR-7 — the level itself is the admin's decision; the enum only
            // constrains it to the three levels ADR-024 §5 defines.
            'client_visibility_level' => ['required', Rule::enum(TeamVisibilityLevel::class)],
            // Master switch. Off = this company's leaders get no team screen
            // at all (DownlineService::resolveLevel() then behaves exactly
            // as if the row were missing).
            'is_enabled' => ['required', 'boolean'],
        ];
    }
}
