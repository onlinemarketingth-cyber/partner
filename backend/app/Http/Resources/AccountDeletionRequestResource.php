<?php

namespace App\Http\Resources;

use App\Models\AccountDeletionRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * MOB-12 (2026-10-02) — one account deletion request, as the ADMIN queue sees it.
 *
 * `user` is the person as they are NOW: before approval that is who asked
 * (name, email, phone — what an admin needs to recognise them and, if
 * needed, to call them about money still owed); after approval it is the
 * anonymised placeholder, which is correct — the request row is not a place
 * where the deleted details live on.
 *
 * The three counts are INFORMATIONAL warnings (owner decision: approval is
 * not blocked by them), computed in one batch by AccountDeletionImpactService
 * and handed in by the controller. Money is integer satang (BR-3); the screen
 * divides by 100, nothing here does. A resource built without counts (null)
 * sends null rather than zeros, so "not computed" can never read as "nothing
 * is owed".
 *
 * @mixin AccountDeletionRequest
 */
class AccountDeletionRequestResource extends JsonResource
{
    /**
     * @param  array{pending_commission_satang: int, downline_count: int, client_count: int}|null  $impact
     */
    public function __construct($resource, private readonly ?array $impact = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            // MOB-12 follow-up (2026-10-03) — `immediate` (deleted on the
            // agent's own request: nothing unpaid, or waived) | `admin`
            // (decided from this queue) | null (still pending).
            'resolution' => $this->resolution?->value,
            // Satang (BR-3) the agent gave up on an immediate deletion; null
            // when nothing was waived.
            'forfeited_commission_satang' => $this->forfeited_commission_satang,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy === null ? null : [
                'id' => $this->decidedBy->id,
                'name' => $this->decidedBy->name,
            ]),
            'company' => $this->whenLoaded('company', fn () => $this->company === null ? null : [
                'id' => $this->company->id,
                'name' => $this->company->name,
            ]),
            'user' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ]),
            'pending_commission_satang' => $this->impact['pending_commission_satang'] ?? null,
            'downline_count' => $this->impact['downline_count'] ?? null,
            'client_count' => $this->impact['client_count'] ?? null,
        ];
    }
}
