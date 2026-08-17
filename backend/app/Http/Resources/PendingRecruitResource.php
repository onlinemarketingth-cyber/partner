<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-115 / TASK-116 point 3 — one person waiting in a team leader's own
 * "รออนุมัติ" list.
 *
 * WHY NOT UserResource: UserResource exposes email, phone, national_id_masked,
 * bank_name and bank_account_holder_name. A team leader is not entitled to
 * any of that — TeamNodeResource (ADR-024 §3, the only other place a leader
 * reads another user) deliberately publishes name + avatar + cert tier and
 * nothing else, and says in its own docblock that widening it "is a new human
 * decision, not a field to quietly add". This Resource holds that same line,
 * and the TASK-115 spec repeats it: "returning no PII beyond what a team
 * leader already sees on /me/team".
 *
 * WHAT IS DELIBERATELY NOT HERE: email, phone, national_id (in any form),
 * bank details, password state, approval_rejection_reason, manager_id, or
 * anything about the recruit's clients or numbers.
 *
 * FLAGGED FOR ag-lead: a leader therefore decides on a name and a signup
 * date. In practice they know who they invited — they sent the link — and
 * the invite_link label below tells them which campaign it came from. If
 * that turns out to be too thin in UAT, adding `email` is a human decision
 * (a leader seeing a teammate's email is a PDPA/§6 widening), not an
 * implementation detail.
 */
class PendingRecruitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            // The derived "first last" column User maintains in its saving hook.
            'name' => $this->name,
            'avatar_url' => $this->avatar_path
                ? Storage::disk('public')->url($this->avatar_path)
                : null,
            'registered_at' => $this->created_at,
            // NOT the timestamp — a boolean. A leader needs to know that
            // approving an unverified person will not actually let them in
            // yet (the login gate still blocks on verification), so TASK-116
            // can warn them. The exact time they clicked a link in their
            // inbox is nobody else's business.
            'email_verified' => $this->email_verified_at !== null,
            // The leader's OWN link — their data, not the recruit's. Lets the
            // UI group "who came from which campaign" when a leader runs
            // several labelled links at once (ADR-025 §3).
            'invite_link' => $this->whenLoaded('recruitedViaAgentLink', fn () => $this->recruitedViaAgentLink ? [
                'id' => (int) $this->recruitedViaAgentLink->id,
                'label' => $this->recruitedViaAgentLink->label,
            ] : null),
        ];
    }
}
