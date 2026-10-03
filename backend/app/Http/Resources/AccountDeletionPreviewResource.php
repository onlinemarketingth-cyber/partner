<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * MOB-12 follow-up (2026-10-03) — what the agent's deletion dialog needs to
 * know before they choose (AccountDeletionService::preview()).
 *
 *   pending_commission_satang  int, satang (BR-3) — the portal divides by 100
 *   can_waive                  bool — false while some of it is in a payout
 *   waive_blocked_reason       Thai sentence when can_waive is false, else null
 *
 * Informational: the POST recomputes everything under lock, so a stale
 * preview can never cause a wrong deletion — only a 422/409 to retry.
 *
 * @property array{pending_commission_satang: int, can_waive: bool, waive_blocked_reason: ?string} $resource
 */
class AccountDeletionPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'pending_commission_satang' => (int) $this->resource['pending_commission_satang'],
            'can_waive' => (bool) $this->resource['can_waive'],
            'waive_blocked_reason' => $this->resource['waive_blocked_reason'],
        ];
    }
}
