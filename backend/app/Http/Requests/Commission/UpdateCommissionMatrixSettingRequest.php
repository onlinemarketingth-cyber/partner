<?php

namespace App\Http\Requests\Commission;

use App\Enums\Ability;
use App\Enums\MatrixSpilloverRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-011/TASK-030 — Company Admin (own company) / Super Admin only,
// same shape as UpdateCommissionBinarySettingRequest. width/depth are
// BR-7 (never defaulted here); spillover_rule is a fixed vocabulary
// (see MatrixSpilloverRule's own docblock — only 'breadth' is actually
// implemented right now).
class UpdateCommissionMatrixSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::SettingsCommissionMatrixUpdate);
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
            'width' => ['required', 'integer', 'min:1', 'max:100'], // sanity ceiling only, not a BR-7 value
            'depth' => ['required', 'integer', 'min:1', 'max:100'],
            'spillover_rule' => ['required', Rule::enum(MatrixSpilloverRule::class)],
        ];
    }
}
