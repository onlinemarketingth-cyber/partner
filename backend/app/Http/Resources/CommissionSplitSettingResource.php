<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TASK-174 — wraps the plain array the Controller assembles from
 * CommissionSplitSettingService (never a raw model, §7). company_id is
 * deliberately NOT echoed back, same as TeamVisibilitySettingResource: the
 * caller already knows which company they asked about, and echoing it
 * invites a frontend to start treating it as a writable field.
 */
class CommissionSplitSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = ['is_enabled' => $this['is_enabled']];

        // Spec §6 — the count of still-unpaid referrals carrying a stored
        // split, so Admin can show what turning the switch back ON would
        // resume. An ABSENT key (not 0) when the caller is an Agent: an
        // Agent has no business reading a company-wide backlog figure, and
        // absent-vs-zero is the same distinction TeamClientResource draws.
        if (array_key_exists('pending_referrals_with_stored_split', $this->resource)) {
            $payload['pending_referrals_with_stored_split'] = $this['pending_referrals_with_stored_split'];
        }

        return $payload;
    }
}
