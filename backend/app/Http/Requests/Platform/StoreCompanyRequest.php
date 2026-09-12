<?php

namespace App\Http\Requests\Platform;

use App\Enums\CommissionPlanType;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

// Only Super Admin can ever reach this (CompanyPolicy::create()) — a
// Company is the tenant boundary itself, not a tenant-scoped resource,
// so there is no company_id to force/validate here at all.
class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Company::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:companies,slug'],
            'is_active' => ['sometimes', 'boolean'],
            // ADR-006 Round 3/4 — defaults to unilevel (Company::commission_plan_type
            // migration default) when omitted. 'binary' is accepted here (schema
            // supports it) but has no working CommissionService yet — frontend-admin
            // shows it as "อยู่ระหว่างพัฒนา" (human decision 2026-07-14).
            'commission_plan_type' => ['sometimes', new Enum(CommissionPlanType::class)],
            /*
             * `commission_basis` was accepted here for a few hours on
             * 2026-09-12 and was moved to PUT /commission-settings the same
             * day. Two doors onto one column is two gates to keep in step,
             * and these two are not the same question: this endpoint asks
             * "may you administer this company", and the basis asks "may you
             * change what every percentage in it is a percentage of". Do not
             * add it back here.
             */
        ];
    }
}
