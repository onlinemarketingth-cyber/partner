<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

// Section 5 rule 6 — the file itself is validated for type/size here;
// where it's stored and how it's served is ClientDocumentService's job.
class StoreClientDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\ClientDocument::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'], // 10MB cap, placeholder allow-list — TODO: CONFIRM (product) real document types needed
        ];
    }
}
