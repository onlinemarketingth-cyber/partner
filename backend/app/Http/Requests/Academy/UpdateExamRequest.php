<?php

namespace App\Http\Requests\Academy;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('exam'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cert_tier_id' => ['sometimes', 'required', 'integer', 'exists:cert_tiers,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'passing_score' => ['sometimes', 'required', 'integer', 'min:0'],
            'config' => ['nullable', 'array'],
        ];
    }
}
