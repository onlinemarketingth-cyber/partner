<?php

namespace App\Http\Requests\Commission;

use App\Enums\CommissionRateType;
use App\Models\AgentRank;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-011/TASK-031 — volume_threshold (satang) and rate_value are BR-7,
// never defaulted here. No overlap/uniqueness invariant is enforced —
// unlike commission_rules/commission_override_rules/
// commission_matrix_level_rates, a rank ladder has no date-range
// dimension; sort_order is simply admin-managed display order.
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
}
