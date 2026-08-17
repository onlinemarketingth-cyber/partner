<?php

namespace App\Http\Requests\Gamification;

use App\Services\Gamification\BadgeConditionEvaluator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Phase 10 — same nullable-company_id "own company or platform default"
// shape as StoreGamificationRuleRequest: only Super Admin may set/omit
// company_id to null, Company Admin is always forced to their own
// company (enforced in BadgeService, same defense-in-depth pattern as
// UserService::create()).
class StoreBadgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Badge::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [
                Rule::prohibitedIf(fn () => ! $this->user()->isSuperAdmin()),
                'nullable',
                'integer',
                Rule::exists('companies', 'id'),
            ],
            'key' => ['required', 'string', 'max:255', Rule::unique('badges', 'key')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:1000'],
            'icon' => ['required', 'string', 'max:255'],
            // condition_config is OPTIONAL — a badge with none stays
            // manual-award-only (BadgeAutoAwardService skips it
            // entirely). When present, every entry must use one of the
            // whitelisted metrics/operators BadgeConditionEvaluator
            // actually understands — anything else is rejected at
            // authoring time rather than silently never firing later.
            'condition_config' => ['nullable', 'array'],
            'condition_config.*.metric' => ['required_with:condition_config', Rule::in(BadgeConditionEvaluator::SUPPORTED_METRICS)],
            'condition_config.*.operator' => ['required_with:condition_config', Rule::in(BadgeConditionEvaluator::SUPPORTED_OPERATORS)],
            'condition_config.*.value' => ['required_with:condition_config', 'integer', 'min:0'],
        ];
    }
}
