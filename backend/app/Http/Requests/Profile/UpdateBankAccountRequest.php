<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

// TASK-044 Phase A — self-service "edit my own bank payout details"
// (Profile Settings), same self-scoped pattern as UpdateNameRequest
// (always operates on $request->user(), never a route-bound {user}).
// All 3 fields optional/nullable per the task spec — an agent may clear
// or partially fill these; the CSV export (separate task item) is
// designed to flag missing bank info per row rather than require it
// up front.
class UpdateBankAccountRequest extends FormRequest
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
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_holder_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
