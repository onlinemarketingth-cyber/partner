<?php

namespace App\Http\Requests\Customer;

use App\Enums\ClientActivityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

// TASK-015 — authorize() checks 'update' against the ClientActivity
// itself (route-bound as {clientActivity}), since ClientActivityPolicy
// ::update is narrower than view (only the original logger).
class UpdateClientActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('clientActivity'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', new Enum(ClientActivityType::class)],
            'summary' => ['sometimes', 'required', 'string', 'max:5000'],
            'occurred_at' => ['nullable', 'date'],
            'follow_up_at' => ['nullable', 'date'],
        ];
    }
}
