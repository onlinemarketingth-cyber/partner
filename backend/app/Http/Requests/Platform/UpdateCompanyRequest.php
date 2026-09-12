<?php

namespace App\Http\Requests\Platform;

use App\Enums\CommissionBasis;
use App\Enums\CommissionPlanType;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('company'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = $this->route('company');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash', Rule::unique('companies', 'slug')->ignore($this->route('company'))],
            'is_active' => ['sometimes', 'boolean'],
            // ADR-006 Round 3/4 — see StoreCompanyRequest's comment.
            'commission_plan_type' => ['sometimes', new Enum(CommissionPlanType::class)],
            /*
             * 2026-09-12 (owner: "ทำแผน PV") — the other half of the same
             * sentence: commission_plan_type says WHO is paid, this says
             * what a percentage is a percentage OF (the sale price, or the
             * product's PV). Super-Admin-only by virtue of living on this
             * endpoint at all, which is the 2026-09-11 decision that every
             * commission setting is.
             *
             * No `nullable`: there is no "unset" basis. A company either
             * pays on price or on points, and the column defaults to
             * 'price' so the question is always already answered.
             */
            'commission_basis' => ['sometimes', new Enum(CommissionBasis::class)],
            // ADR-017 (TASK-054) — BR-7 admin-editable payment collection
            // config, shown on the public /pay/{token} page. All nullable.
            'payment_promptpay_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_bank_account_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_bank_account_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            // ADR-026 §3.3 (TASK-132) — least-specific scope. BR-6: a
            // company may only default to one of ITS OWN templates.
            // No StoreCompanyRequest counterpart on purpose: a company
            // being created has no templates yet.
            'default_pipeline_template_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pipeline_templates', 'id')->where('company_id', $company->id)],
        ];
    }
}
