<?php

namespace App\Http\Requests\Gamification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLevelThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('level_threshold'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'level_number' => [
                'sometimes', 'integer', 'min:1',
                Rule::unique('level_thresholds', 'level_number')->ignore($this->route('level_threshold')),
            ],
            'xp_required' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
