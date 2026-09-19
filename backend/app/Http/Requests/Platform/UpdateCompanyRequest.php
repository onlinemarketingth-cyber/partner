<?php

namespace App\Http\Requests\Platform;

use App\Models\Company;
use App\Support\Money\SupportedCurrency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            /*
             * 2026-09-19 — ISO 4217, the currency this tenant's money is in.
             *
             * `Rule::in(SupportedCurrency::codes())` and not a free string:
             * every amount in this system is an integer number of HUNDREDTHS
             * (BR-3 satang) and every formatter divides by 100, so a
             * 0-decimal currency like JPY would make every stored figure a
             * hundred times the real one, silently. The allowed list is
             * restricted to hundredth-based currencies for that reason and
             * the class says so at length.
             *
             * Editable rather than set-once, because a tenant provisioned
             * before this existed has to be able to correct it. That is not a
             * re-denomination: changing it relabels the same stored numbers,
             * and nothing converts them — which is exactly why this is a
             * Super-Admin-only endpoint and the change is audited by
             * CompanyService like every other field here.
             */
            'currency_code' => ['sometimes', 'string', 'size:3', Rule::in(SupportedCurrency::codes())],
            /*
             * `commission_plan_type` AND `commission_basis` are both gone from
             * here as of 2026-09-12. Both are written by PUT
             * /commission-settings now, behind
             * Ability::SettingsCommissionPlanUpdate.
             *
             * Two doors onto one column is two gates to keep in step, and
             * these are not the same question: this endpoint asks "may you
             * administer this company — rename it, change its bank account,
             * delete it", and those two ask "may you change who gets paid and
             * what they are paid a percentage of". They answer the same today
             * and nothing makes them keep agreeing.
             *
             * The plan type's removal also has a UI reason. It was the only
             * thing forcing step 2 of the commission screen to send an admin
             * to /companies to change the plan — a bounce the owner reported
             * as "ทำให้ UI สับสน" on 2026-09-12. Moving the write let the link
             * become a button.
             *
             * StoreCompanyRequest still accepts the plan type: provisioning a
             * tenant with a plan is not an edit of an existing value.
             * Do not add either back here.
             */
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
