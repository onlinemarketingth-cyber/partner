<?php

namespace App\Http\Requests\Customer;

use App\Enums\ClientActivityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

// TASK-015 — authorize() checks against the parent Client (route-bound
// as {client}), not the ClientActivity itself (there isn't one yet) —
// mirrors StoreClientDocumentRequest's shape.
class StoreClientActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('client'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', new Enum(ClientActivityType::class)],
            'summary' => ['required', 'string', 'max:5000'],
            // Defaults to now() in the Service when omitted — backdatable
            // for logging a contact that already happened.
            'occurred_at' => ['nullable', 'date'],
            'follow_up_at' => ['nullable', 'date'],
        ];
    }
}
