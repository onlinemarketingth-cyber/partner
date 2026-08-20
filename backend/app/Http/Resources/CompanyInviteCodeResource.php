<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TASK-233 — the admin view of a company signup link.
 *
 * AUTHENTICATED ONLY. There is no public counterpart and there must not
 * be: `used_count`, `max_uses` and `label` together tell an anonymous
 * visitor how big a company's recruitment drive is and how much room is
 * left in it. The public resolver returns the company NAME and nothing
 * else, exactly as TASK-114's `resolve-ref-token` already does for team
 * invites and for the same reason.
 */
class CompanyInviteCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $frontendUrl = rtrim((string) config('services.agent_portal.frontend_url'), '/');

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->name),
            'code' => $this->code,
            'label' => $this->label,

            // The whole point of the feature. `shortUrl()` is preferred so
            // that a code created before its tracked link existed still
            // renders something usable rather than an empty box.
            'signup_url' => $this->shortUrl() ?? "{$frontendUrl}/c/{$this->code}",

            // Null on either means UNLIMITED / never expires. Passed
            // through as null rather than 0 or a sentinel date so the UI
            // can say "ไม่จำกัด" instead of having to guess what a zero
            // was supposed to mean.
            'expires_at' => $this->expires_at,
            'max_uses' => $this->max_uses,
            'used_count' => $this->used_count,
            'revoked_at' => $this->revoked_at,
            'is_valid' => $this->isValid(),

            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at,
        ];
    }
}
