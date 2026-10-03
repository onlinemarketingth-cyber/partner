<?php

namespace App\Http\Requests\Account;

use App\Enums\AccountDeletionRequestStatus;
use App\Models\AccountDeletionRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * MOB-12 (2026-10-02) — the admin queue's filters.
 *
 * Authorized here (AccountDeletionRequestPolicy::viewAny — Company Admin /
 * Super Admin) so an agent gets its 403 before any validation message could
 * describe the endpoint to them. `company_id` is accepted for the
 * Super Admin header scope and ignored for everyone else by
 * CompanyScopeFilter, which never trusts it from a Company Admin.
 */
class IndexAccountDeletionRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AccountDeletionRequest::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(AccountDeletionRequestStatus::class)],
            'company_id' => ['sometimes', 'integer'],
        ];
    }

    public function status(): AccountDeletionRequestStatus
    {
        return AccountDeletionRequestStatus::from(
            $this->validated('status') ?? AccountDeletionRequestStatus::Pending->value,
        );
    }
}
