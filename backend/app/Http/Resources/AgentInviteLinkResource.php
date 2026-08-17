<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TASK-113 / ADR-025 §3 — the AUTHENTICATED view of a recruit link (the
 * leader's own "ชวนเข้าทีม" list, an Admin's oversight list, and the
 * mint result).
 *
 * There is no public counterpart to this Resource. TASK-114's
 * `resolve-ref-token` is unauthenticated and must return ONLY
 * { company_name, inviter_name } — it must not reuse this shape, because
 * `used_count`, `max_uses` and `token` here would tell an anonymous
 * visitor how big the recruiting drive is and let them enumerate it.
 */
class AgentInviteLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Same construction as ProductShareLinkResource::public_url — points
        // at the FRONTEND (Agent Portal) page, not this API: a human clicks
        // this link and needs the rendered RegisterView, which TASK-116 will
        // teach to read ?ref=<token>.
        $frontendUrl = rtrim((string) config('services.agent_portal.frontend_url'), '/');

        return [
            'id' => $this->id,
            // Included for parity with ProductShareLinkResource and because
            // an Admin's company-wide list needs to show WHOSE link each row
            // is. Safe: the Policy already decided this caller may see the
            // row at all.
            'company_id' => $this->company_id,
            'agent_id' => $this->agent_id,
            'label' => $this->label,
            // The raw token, exactly as ProductShareLinkResource exposes its
            // own — this response only ever reaches someone the Policy has
            // already cleared to revoke the link, and TASK-116 needs it to
            // render the QR code client-side.
            'token' => $this->token,
            'public_url' => "{$frontendUrl}/register?ref={$this->token}",
            'used_count' => $this->used_count,
            // null on either limit means UNLIMITED (ADR-025 §3). Passed
            // through as null rather than 0 or a sentinel so the UI can say
            // "ไม่จำกัด" instead of guessing.
            'max_uses' => $this->max_uses,
            'expires_at' => $this->expires_at,
            'revoked_at' => $this->revoked_at,
            // Read from the model method, never recomputed here — isUsable()
            // is the single source of truth for all three conditions, and a
            // second copy of that rule in a Resource is exactly how it drifts.
            'is_usable' => $this->resource->isUsable(),
            'created_at' => $this->created_at,
        ];
    }
}
