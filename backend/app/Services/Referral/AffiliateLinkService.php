<?php

namespace App\Services\Referral;

use App\Models\AffiliateLink;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// ADR-011/TASK-032 — minting is authenticated + Policy-checked
// (AffiliateLinkController::store()); only the resulting token is ever
// unauthenticated (consumed via the two public routes).
class AffiliateLinkService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): AffiliateLink
    {
        $agentId = $actor->isAgent() ? $actor->id : $data['agent_id'];
        $agent = User::findOrFail($agentId);

        // BR-1 (Access Gate) — an affiliate link is a selling channel
        // like SWS Referral/Pipeline; same gate ReferralService::create()
        // already enforces for the resolved referring agent, reused here
        // rather than invented separately.
        if (! $agent->hasPassedCertTier('basic')) {
            throw ValidationException::withMessages([
                'agent_id' => 'BR-1: this agent has not passed the Basic certification yet, so no affiliate link can be minted for them.',
            ]);
        }

        return AffiliateLink::create([
            'company_id' => $actor->company_id,
            'agent_id' => $agentId,
            'product_id' => $data['product_id'] ?? null,
            // 32 random bytes -> 64 hex chars: unguessable (Section 5
            // rule 5), same convention as SalesMaterialShareLinkService.
            'token' => Str::random(64),
        ]);
    }
}
