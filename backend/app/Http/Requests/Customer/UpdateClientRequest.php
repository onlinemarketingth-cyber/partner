<?php

namespace App\Http\Requests\Customer;

use App\Enums\ClientStatus;
use App\Rules\ThaiNationalId;
use App\Support\ThailandProvinces;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('client'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            // TASK-049 — national ID (PDPA §6, encrypted at rest).
            'national_id' => ['nullable', 'string', new ThaiNationalId],
            'consent_given_at' => ['nullable', 'date'],
            'health_notes' => ['nullable', 'string', 'max:5000'],
            // Free text, not a fixed enum — see StoreClientRequest's
            // comment (BR-7: channel list isn't finalized/agreed).
            'lead_source' => ['nullable', 'string', 'max:255'],
            // TASK-056 Sprint P2 — client segmentation (BR-7 config).
            'client_category_id' => ['nullable', 'integer', Rule::exists('client_categories', 'id')->where('company_id', $this->user()->company_id)],
            // TASK-014 demographic fields — same rules as StoreClientRequest.
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:2000'],
            'province' => ['nullable', 'string', Rule::in(ThailandProvinces::LIST)],
            'occupation' => ['nullable', 'string', 'max:255'],
            // Manual CRM-style lead status — anyone who can update the
            // client (referring Agent / Company Admin / Super Admin,
            // via ClientPolicy::update) may change it.
            'status' => ['sometimes', 'required', new Enum(ClientStatus::class)],
        ];
    }
}
