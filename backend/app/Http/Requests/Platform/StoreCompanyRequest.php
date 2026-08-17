<?php

namespace App\Http\Requests\Platform;

use App\Enums\CommissionPlanType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

// Only Super Admin can ever reach this (CompanyPolicy::create()) — a
// Company is the tenant boundary itself, not a tenant-scoped resource,
// so there is no company_id to force/validate here at all.
class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Company::class);
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
        ];
    }
}
