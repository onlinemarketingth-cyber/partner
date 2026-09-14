<?php

namespace App\Http\Requests\Commission;

use App\Enums\Ability;
use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionRateType;
use App\Http\Requests\Catalog\Concerns\ValidatesProductOwnership;
use App\Http\Requests\Catalog\Concerns\ValidatesProductTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 2026-09-14 — the input half of "บันทึกแล้วจะเกิดอะไรขึ้น".
 *
 * Mirrors the two rate forms' own fields because it has to: a preview built
 * from a different shape than the save would be previewing something else.
 *
 * SUPER ADMIN ONLY, via Ability::SettingsCommissionPlanUpdate — the same gate
 * as writing a rate. This reads nothing an admin could not already see, but it
 * is only ever useful to somebody about to save, and granting a preview to
 * roles that cannot save would be a control this screen then has to hide
 * anyway (the house rule: hide, never 403).
 *
 * WRITES NOTHING. There is no dry_run flag to get wrong, because there is no
 * non-dry path through this endpoint at all — the copy-rates endpoint pairs a
 * preview and a write behind one flag and needs a test to prove the flag is
 * honoured; this one needs no such test because it has no write to reach.
 */
class PreviewCommissionRateImpactRequest extends FormRequest
{
    use ValidatesProductOwnership;
    use ValidatesProductTaxonomy;

    /** Same resolution as the write Requests: only a Super Admin names a company. */
    protected function effectiveCompanyId(): ?int
    {
        return $this->user()->isSuperAdmin()
            ? $this->integer('company_id') ?: null
            : $this->user()->company_id;
    }

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
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            // Which of the two ladders. They are separate tables with separate
            // rates and, since 2026-09-14, separate deduction modes — one
            // endpoint answering for both would have to guess which.
            'kind' => ['required', Rule::in(['agent', 'leader'])],
            'rate_type' => ['required', Rule::enum(CommissionRateType::class)],
            'rate_value' => ['required', 'integer', 'min:0'],
            // Same mutual prohibition as the write: at most ONE scope column,
            // both absent = the company-wide default.
            /*
             * 2026-09-14 — the SAME rules as the write, not looser ones.
             *
             * These were bare `exists:` checks, and that is how a real bug
             * stayed hidden for a day: the preview happily reported three
             * products a platform category would change, and บันทึก then
             * refused the very same category. A preview that accepts more than
             * the save is worse than no preview — it is a green light in front
             * of a closed door.
             */
            'product_id' => [
                'nullable', 'integer',
                $this->configurableProductRule($this->effectiveCompanyId()),
                Rule::prohibitedIf(fn () => $this->filled('product_category_id')),
            ],
            'product_category_id' => [
                'nullable', 'integer',
                $this->taxonomyRule('product_categories', $this->effectiveCompanyId()),
                Rule::prohibitedIf(fn () => $this->filled('product_id')),
            ],
            'override_mode' => ['sometimes', 'nullable', Rule::enum(CommissionOverrideMode::class)],
            /*
             * The row being EDITED. Without it an edit reports the rate as the
             * thing blocking itself — true of the database and useless to the
             * reader, who is looking at the very form that owns that row.
             */
            'exclude_rule_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
