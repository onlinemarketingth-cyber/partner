<?php

namespace App\Http\Requests\Academy;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 2026-09-28 — "อนุมัติการเรียน" for several agents at once, from the roster.
 *
 * The same override as StoreUserCertificationRequest (BR-1 admin grant
 * without an exam), the same ability, the same target rule — an active,
 * non-deleted AGENT, in the actor's own company unless the actor is a Super
 * Admin. Only the shape differs: a list of users instead of one.
 *
 * Capped at 200 per request: the roster screen selects from what it shows,
 * and a request that could name every user on the platform is a request
 * nobody meant to send.
 */
class BulkGrantUserCertificationRequest extends FormRequest
{
    public const MAX_USERS = 200;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Ability::AcademyCertificationGrant);
    }

    public function rules(): array
    {
        $userExists = Rule::exists('users', 'id')
            ->where('role', 'agent')
            // "Active" on the roster is deleted_at IS NULL (UserResource).
            ->whereNull('deleted_at');

        if (! $this->user()->isSuperAdmin()) {
            $userExists->where('company_id', $this->user()->company_id);
        }

        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_USERS],
            'user_ids.*' => ['required', 'integer', 'distinct', $userExists],
            'cert_tier_id' => ['required', 'integer', Rule::exists('cert_tiers', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'user_ids.*.exists' => 'มีรายชื่อที่อนุมัติไม่ได้ (ไม่ใช่ตัวแทนที่ใช้งานอยู่ในบริษัทนี้) — ยังไม่ได้อนุมัติใครเลย',
            'user_ids.max' => 'อนุมัติได้ครั้งละไม่เกิน '.self::MAX_USERS.' คน',
        ];
    }
}
