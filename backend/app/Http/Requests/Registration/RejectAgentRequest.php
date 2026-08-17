<?php

namespace App\Http\Requests\Registration;

use Illuminate\Foundation\Http\FormRequest;

// TASK-020 — the real authorization gate is the controller's
// $this->authorize('update', $user) call (reusing UserPolicy — see
// AgentApprovalController's own comment); this Form Request only
// validates the optional reason text.
class RejectAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
