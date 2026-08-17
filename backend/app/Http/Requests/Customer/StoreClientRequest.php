<?php

namespace App\Http\Requests\Customer;

use App\Rules\ThaiNationalId;
use App\Support\ThailandProvinces;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// PDPA (Section 6): consent_given_at is how this system currently
// records "requires consent" (ERD-001 open question #5 — the full
// consent flow beyond a timestamp is still unconfirmed, not guessed
// here). health_notes is free text, encrypted at rest by the model
// cast — never logged or exposed beyond what ClientResource decides.
class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Client::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            // TASK-049 — national ID (PDPA §6, encrypted at rest). Optional;
            // when present must be a valid Thai 13-digit ID (checksum).
            'national_id' => ['nullable', 'string', new ThaiNationalId],
            'consent_given_at' => ['nullable', 'date'],
            'health_notes' => ['nullable', 'string', 'max:5000'],
            // Free text, not a fixed enum — the channel list isn't
            // finalized/agreed (BR-7), so it's never hardcoded. The UI
            // offers common suggestions without enforcing them.
            'lead_source' => ['nullable', 'string', 'max:255'],
            // TASK-056 Sprint P2 — client segmentation (BR-7 config).
            'client_category_id' => ['nullable', 'integer', Rule::exists('client_categories', 'id')->where('company_id', $this->user()->company_id)],
            // TASK-014 demographic fields — general personal data
            // (Section 6), all optional. province is a fixed geographic
            // fact (not BR-7 territory, see ThailandProvinces).
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:2000'],
            'province' => ['nullable', 'string', Rule::in(ThailandProvinces::LIST)],
            'occupation' => ['nullable', 'string', 'max:255'],
            // status is intentionally NOT accepted here — every new
            // client always starts at ClientStatus::New, forced
            // server-side in ClientService::create(). Only changeable
            // afterwards via UpdateClientRequest.
            // referring_agent_id is NEVER accepted from the client for
            // an Agent (always forced to self) — only Company
            // Admin/Super Admin may assign a different agent, per
            // ClientService. Rejected at validation (422) for an Agent,
            // not just silently discarded downstream — belt-and-braces
            // per Section 6 "never trust client input".
            'referring_agent_id' => [
                Rule::prohibitedIf(fn () => $this->user()->isAgent()),
                Rule::requiredIf(fn () => ! $this->user()->isAgent()),
                'integer',
                Rule::exists('users', 'id')->where('company_id', $this->user()->company_id),
            ],
        ];
    }
}
