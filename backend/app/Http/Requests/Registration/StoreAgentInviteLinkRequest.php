<?php

namespace App\Http\Requests\Registration;

use App\Models\AgentInviteLink;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-113 / ADR-025 §3 — the only client-supplied part of a recruit link.
 *
 * Contrast with StoreProductShareLinkRequest, which lets a Company Admin
 * mint on behalf of another agent via `agent_id`: there is deliberately no
 * such field here. A recruit link binds the recruit to a MANAGER
 * (`manager_id` in TASK-114), so "who owns this link" is a hierarchy
 * decision, and ADR-025 §1 hands that decision to the flag alone — an
 * Admin grants `is_team_leader` and the leader mints their own link. The
 * owner is therefore always the authenticated caller.
 */
class StoreAgentInviteLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Coarse gate only (AgentInviteLinkPolicy::create() returns true for
        // anyone authenticated). The real capability check — is_team_leader,
        // ADR-025 §1 — is in AgentInviteLinkService::create() and surfaces as
        // a 422, not a 403: "you are not a team leader" is a business-rule
        // outcome the UI explains, not an authorisation failure.
        return $this->user()->can('create', AgentInviteLink::class);
    }

    /**
     * Every field is optional (ADR-025 §3 — "ตั้งค่าได้ทั้งวันหมดอายุ และ
     * จำนวนคน หรือไม่ limit"): an empty body is a perfectly valid request
     * that mints an unlimited, never-expiring link.
     *
     * NOTE what is absent, and why absence is the enforcement mechanism:
     * `company_id`, `agent_id`, `token`, `used_count` and `revoked_at` have
     * no rule here, so validated() strips them, so the Service — which
     * reads ONLY validated() — can never see them however they were spelled
     * in the body. They are derived server-side (BR-6 / Section 5 rule 5).
     * They are silently ignored rather than 'prohibited' on purpose: a
     * future client that helpfully echoes a whole link object back should
     * get a mint, not a confusing 422 about a field it was never told to
     * own.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:255'],
            // after:now, not after:today — a link that expired earlier the
            // same day is dead on arrival, and isUsable() would agree.
            'expires_at' => ['nullable', 'date', 'after:now'],
            // min:1 — a zero-use link is unusable the moment it exists.
            // No upper bound: any cap would be an invented business value
            // (BR-7). null means unlimited, which is the documented default.
            'max_uses' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
