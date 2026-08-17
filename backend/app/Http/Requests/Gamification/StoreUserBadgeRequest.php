<?php

namespace App\Http\Requests\Gamification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// The manual "award a badge" action input — Company Admin/Super Admin
// only (see UserBadgePolicy::award()). Both FKs are tenant-scoped to
// the actor's own company; a Super Admin awarding across companies
// isn't a supported flow in this phase (they'd act as that company's
// admin instead — keeps this Request simple, same as most Store
// requests in this codebase not special-casing Super Admin unless a
// concrete need exists).
class StoreUserBadgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('award', \App\Models\UserBadge::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('company_id', $this->user()->company_id)->where('role', 'agent'),
            ],
            'badge_id' => [
                'required',
                'integer',
                Rule::exists('badges', 'id')->where(fn ($query) => $query->where('company_id', $this->user()->company_id)->orWhereNull('company_id')),
            ],
        ];
    }
}
