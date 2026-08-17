<?php

namespace App\Http\Requests\Academy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// `config` is a placeholder json blob (ERD-001 open question #5, exam
// engine shape not yet decided) — accepted as-is, not validated deeply.
class StoreExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Exam::class);
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
            'cert_tier_id' => ['required', 'integer', 'exists:cert_tiers,id'],
            'title' => ['required', 'string', 'max:255'],
            'passing_score' => ['required', 'integer', 'min:0'],
            'config' => ['nullable', 'array'],
        ];
    }
}
