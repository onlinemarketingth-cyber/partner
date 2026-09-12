<?php

namespace App\Http\Requests\Commission;

use App\Enums\Ability;
use App\Enums\CommissionBasis;
use App\Enums\CommissionPlanType;
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
 * BOTH company-level commission fields are written here, and that is the one
 * door for each of them:
 *
 *   commission_plan_type  WHO gets paid (Unilevel / Binary / Matrix / …)
 *   commission_basis      what a percentage is a percentage OF (price / PV)
 *
 * The plan type was accepted on the companies resource until 2026-09-12, when
 * the owner pressed "เปลี่ยนแผน" on step 2 of the commission screen and was
 * bounced to /companies to finish the job ("ทำให้ UI สับสน"). Sending somebody
 * to another screen to answer a question this screen just asked them is the
 * dead end the whole 4-step redesign exists to remove — and the only reason it
 * was a link at all was that the write lived behind CompanyPolicy::update.
 * Moving the write here removed the reason, so the link could go.
 *
 * `required_without` on both rather than `sometimes` on both: an endpoint
 * whose only job is to set these must not accept a body that sets neither and
 * report success. An admin walking away from a switch that never flipped is
 * the failure this whole area keeps producing.
 */
class UpdateCommissionSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::SettingsCommissionPlanUpdate);
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
            // Either field alone is a valid request — step 2 switches the
            // plan and the basis with two separate controls — but a body
            // carrying neither is not. See the docblock.
            'commission_basis' => ['required_without:commission_plan_type', Rule::enum(CommissionBasis::class)],
            /*
             * NOT nullable. `companies.commission_plan_type` is NOT NULL with
             * a default, and every company runs exactly one plan; "no plan" is
             * not a state this system has, and accepting null here would
             * create one that nothing downstream (Product::effectivePlanType,
             * CommissionService's six-way branch) knows how to answer for.
             *
             * The per-PRODUCT override is the nullable one, and it lives on
             * the products endpoint — different column, different question.
             */
            'commission_plan_type' => ['required_without:commission_basis', Rule::enum(CommissionPlanType::class)],
        ];
    }
}
