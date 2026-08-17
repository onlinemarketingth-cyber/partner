<?php

namespace App\Http\Requests\Gamification;

use App\Enums\GamificationSourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// company_id is never editable here at all (by anyone) — reassigning an
// existing rule from one company (or the platform default) to another
// is not a supported operation; delete and recreate instead. This is
// simpler than StoreGamificationRuleRequest and deliberately so.
class UpdateGamificationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('gamification_rule'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source_type' => ['sometimes', Rule::enum(GamificationSourceType::class)],
            'xp_value' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
