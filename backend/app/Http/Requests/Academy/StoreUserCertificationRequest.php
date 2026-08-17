<?php

namespace App\Http\Requests\Academy;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * BR-1 admin override — Company Admin (own company's agents only) or
 * Super Admin (any company) may manually grant a cert tier. Mirrors
 * StoreProductShareLinkRequest's `agent_id` exists-rule shape (TASK-056),
 * except the company_id scope is only applied for a Company Admin — a
 * Super Admin has no company_id of their own to scope by, and is exactly
 * the role that needs to reach across companies here.
 */
class StoreUserCertificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Ability::AcademyCertificationGrant);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userExists = Rule::exists('users', 'id')->where('role', 'agent');
        if (! $this->user()->isSuperAdmin()) {
            $userExists->where('company_id', $this->user()->company_id);
        }

        return [
            'user_id' => ['required', 'integer', $userExists],
            'cert_tier_id' => ['required', 'integer', Rule::exists('cert_tiers', 'id')],
        ];
    }
}
