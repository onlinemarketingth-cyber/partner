<?php

namespace App\Http\Requests\Commission;

use App\Enums\CommissionRateType;
use App\Models\AgentRank;
use App\Services\Commission\AgentRankLadderInspector;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-011/TASK-031 — volume_threshold (satang) and rate_value are BR-7,
// never defaulted here. No overlap/uniqueness invariant is enforced —
// unlike commission_rules/commission_override_rules/
// commission_matrix_level_rates, a rank ladder has no date-range
// dimension; sort_order is simply admin-managed display order.
//
// 2026-09-24 (owner choice 3ก) — that is still true of any ONE rank, and
// the ladder as a whole gained a guard: see after() below.
class StoreAgentRankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AgentRank::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255'],
            'volume_threshold' => ['required', 'integer', 'min:0'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'rate_type' => ['required', Rule::enum(CommissionRateType::class)],
            'rate_value' => ['required', 'integer', 'min:0'],
            'is_breakaway_rank' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * THE LADDER-LEVEL GUARD (owner choice 3ก, 2026-09-24).
     *
     * Not "the resulting ladder must be valid" — "it must not be MORE
     * broken than it already is". A company holding a legacy mixed-type or
     * out-of-order ladder has to be able to edit its way out, and the
     * strict version refuses every edit on that path because each
     * intermediate ladder is still invalid. See
     * AgentRankLadderInspector::regressions().
     *
     * Skipped when the field rules already failed: the counterfactual
     * ladder would be built from values that will never be saved, and the
     * admin would get a second error about a rung they did not write.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // Mirrors AgentRankService::create()'s own resolution — a
                // Company Admin's company_id is never taken from the request
                // body (BR-6), only a Super Admin may name one.
                $companyId = $this->user()->isSuperAdmin()
                    ? (int) $this->input('company_id')
                    : (int) $this->user()->company_id;

                $inspector = app(AgentRankLadderInspector::class);
                $regressions = $inspector->regressionsForCompany($companyId, null, $this->validated());

                foreach ($inspector->regressionMessages($regressions) as $message) {
                    $validator->errors()->add('rate_value', $message);
                }
            },
        ];
    }
}
