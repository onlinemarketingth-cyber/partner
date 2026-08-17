<?php

namespace App\Http\Requests\Catalog;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-007 — Company Admin (own company) / Super Admin only, same
// visibility shape as CommissionRule management.
class UpdateVideoProcessingSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::SettingsVideoProcessingUpdate);
    }

    protected function effectiveCompanyId(): ?int
    {
        return $this->user()->isSuperAdmin()
            ? $this->integer('company_id') ?: null
            : $this->user()->company_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            // Deliberately generous upper bounds, not fine-tuned business
            // limits — a sanity ceiling only (BR-7's spirit is "don't
            // hardcode a rate/value that should be admin-editable", these
            // ARE admin-editable; this just stops an obvious fat-finger).
            'max_upload_mb' => ['required', 'integer', 'min:10', 'max:2000'],
            'target_resolution' => ['required', 'string', 'in:480p,720p,1080p'],
            'target_bitrate_kbps' => ['required', 'integer', 'min:500', 'max:20000'],
        ];
    }
}
