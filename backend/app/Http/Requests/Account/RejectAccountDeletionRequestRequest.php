<?php

namespace App\Http\Requests\Account;

use App\Models\AccountDeletionRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * MOB-12 (2026-10-02) — an admin declining an account deletion request.
 *
 * The note is optional and short. Authorized here against the route-bound
 * request (AccountDeletionRequestPolicy::decide) — TenantScope has already
 * 404'd another company's id during binding, so this is the agent / wrong-role
 * 403, checked before validation can say anything about the payload.
 */
class RejectAccountDeletionRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('accountDeletionRequest');

        return $target instanceof AccountDeletionRequest
            && ($this->user()?->can('decide', $target) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.max' => 'หมายเหตุยาวได้ไม่เกิน 500 ตัวอักษร',
        ];
    }
}
