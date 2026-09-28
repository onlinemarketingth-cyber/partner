<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE /companies/{company}/purge — 2026-09-26.
 *
 * `confirm_name` is the company's name typed back. Required here so a client
 * that forgets it gets a validation error, not a deletion; whether it MATCHES
 * is checked by CompanyRemovalService, next to the rules it guards.
 */
class RemoveCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('company'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirm_name' => ['required', 'string', 'max:255'],
        ];
    }
}
