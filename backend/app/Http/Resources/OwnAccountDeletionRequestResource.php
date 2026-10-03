<?php

namespace App\Http\Resources;

use App\Enums\AccountDeletionRequestStatus;
use App\Models\AccountDeletionRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * MOB-12 (2026-10-02) — the receipt the AGENT gets back for their own request.
 *
 * By the time this response arrives the agent has already been signed out
 * everywhere, so the app's only job is to say what happened and drop back to
 * the login screen. Two shapes, and the portal branches on `status`:
 *
 *   202 {status: 'pending', requested_at}
 *       — waiting for an admin (the "keep my commission" choice).
 *   200 {status: 'deleted', requested_at, deleted_at, forfeited_commission_satang}
 *       — deleted on the spot (2026-10-03: nothing unpaid, or waived).
 *         forfeited_commission_satang is integer satang (BR-3), null when
 *         nothing was waived.
 *
 * `deleted` is a word for the AGENT, not a request status: on the row it is
 * status = approved + resolution = immediate. The ids and the admin's side of
 * the row are not theirs to act on and are not sent.
 *
 * @mixin AccountDeletionRequest
 */
class OwnAccountDeletionRequestResource extends JsonResource
{
    public const STATUS_DELETED = 'deleted';

    public static function wasDeleted(AccountDeletionRequest $request): bool
    {
        return $request->status === AccountDeletionRequestStatus::Approved;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (! self::wasDeleted($this->resource)) {
            return [
                'status' => $this->status->value,
                'requested_at' => $this->requested_at?->toIso8601String(),
            ];
        }

        return [
            'status' => self::STATUS_DELETED,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'deleted_at' => $this->decided_at?->toIso8601String(),
            'forfeited_commission_satang' => $this->forfeited_commission_satang,
        ];
    }
}
