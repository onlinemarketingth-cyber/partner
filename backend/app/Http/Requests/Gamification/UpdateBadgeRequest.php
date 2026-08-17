<?php

namespace App\Http\Requests\Gamification;

use App\Services\Gamification\BadgeConditionEvaluator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// company_id is never editable here (same reasoning as
// UpdateGamificationRuleRequest) — reassigning a badge from one company
// (or the platform default) to another is not supported; delete and
// recreate instead.
class UpdateBadgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('badge'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['sometimes', 'string', 'max:255', Rule::unique('badges', 'key')->ignore($this->route('badge'))],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:1000'],
            'icon' => ['sometimes', 'string', 'max:255'],
            'condition_config' => ['nullable', 'array'],
            'condition_config.*.metric' => ['required_with:condition_config', Rule::in(BadgeConditionEvaluator::SUPPORTED_METRICS)],
            'condition_config.*.operator' => ['required_with:condition_config', Rule::in(BadgeConditionEvaluator::SUPPORTED_OPERATORS)],
            'condition_config.*.value' => ['required_with:condition_config', 'integer', 'min:0'],
        ];
    }
}
