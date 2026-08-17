<?php

namespace App\Http\Requests\Gamification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Super-Admin-only, platform-wide (see LevelThresholdPolicy — no
// company_id field exists to restrict).
class StoreLevelThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\LevelThreshold::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'level_number' => ['required', 'integer', 'min:1', Rule::unique('level_thresholds', 'level_number')],
            'xp_required' => ['required', 'integer', 'min:0'],
        ];
    }
}
