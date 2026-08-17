<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

// ADR-007 — any same-company user who can already VIEW this material
// may mint a share link for it (Agents included — they're the ones
// actually sending it to prospects, not just Company Admin).
class StoreSalesMaterialShareLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $salesMaterial = $this->route('salesMaterial');

        return $salesMaterial !== null && $this->user()->can('view', $salesMaterial->product);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Sane bounds, not a fine-tuned business value — same BR-7
            // spirit as UpdateVideoProcessingSettingRequest's caps.
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:90'],
        ];
    }
}
