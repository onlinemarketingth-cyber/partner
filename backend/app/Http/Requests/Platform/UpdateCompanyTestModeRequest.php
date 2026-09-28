<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /companies/{company}/test-mode — 2026-09-26.
 *
 * A request of its own rather than a field on UpdateCompanyRequest: the flag
 * decides whether a company may be wiped with its money in it, and the rules
 * for setting it (only while empty, never again after go-live) live in
 * CompanyRemovalService. A field on the general update would be one more door
 * past them.
 */
class UpdateCompanyTestModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('company'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_test' => ['required', 'boolean'],
        ];
    }
}
