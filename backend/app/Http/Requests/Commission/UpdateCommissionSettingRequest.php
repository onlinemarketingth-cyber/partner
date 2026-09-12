<?php

namespace App\Http\Requests\Commission;

use App\Enums\Ability;
use App\Enums\CommissionBasis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 2026-09-12 — the write half of GET/PUT /commission-settings.
 *
 * SUPER ADMIN ONLY, via its own Ability rather than by borrowing
 * CompanyPolicy::update. Those two answer the same today and they are not the
 * same question: CompanyPolicy::update is "may you rename this company, change
 * its bank account, delete it". Routing the commission basis through it would
 * mean that anybody ever granted company administration also, silently,
 * gained the power to change what every percentage in the company is a
 * percentage of — a coupling nobody would choose on purpose, and one that
 * would be discovered at a payout.
 *
 * `commission_plan_type` is NOT accepted here. It is returned by show()
 * because the screen must display which plan the company runs, but changing it
 * stays on the companies resource where it already lives. One column, one
 * write door.
 */
class UpdateCommissionSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::SettingsCommissionBasisUpdate);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only a Super Admin may target a company other than their own.
            // A Company Admin's value is ignored outright — the Controller
            // substitutes the caller's own company_id (BR-6) — but today no
            // Company Admin can reach authorize() at all, so this rule is
            // the shape the siblings use rather than a live path.
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            // Required, not `sometimes`: there is no such thing as "leave the
            // basis as it was" on an endpoint whose only job is to set it, and
            // an absent field silently succeeding is how an admin walks away
            // from a switch that never flipped.
            'commission_basis' => ['required', Rule::enum(CommissionBasis::class)],
        ];
    }
}
