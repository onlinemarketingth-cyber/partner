<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Phase 11 — Super-Admin-only (see UserPolicy::move()).
class MoveUserCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('move', $this->route('user'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
        ];
    }
}
