<?php

namespace App\Services\Referral;

use App\Models\AffiliateLink;
use App\Models\AffiliateLinkClick;
use Illuminate\Http\Request;

/**
 * ADR-011 Section 4 (TASK-032) — records GET /l/{token} hits. PDPA/
 * Section 6: never stores a raw IP — hashed with HMAC-SHA256 keyed by
 * the app's own APP_KEY, not a bare sha256(ip) (a bare hash over the
 * small IPv4 address space is trivially reversible via a precomputed
 * rainbow table, which would defeat the point of hashing it at all —
 * keying by a secret the attacker doesn't have closes that hole while
 * still letting the SAME visitor's clicks be recognized as the same
 * hash for analytics, which a truncated/anonymized IP alone wouldn't
 * reliably do either).
 */
class AffiliateLinkClickService
{
    public function record(AffiliateLink $link, Request $request): AffiliateLinkClick
    {
        return AffiliateLinkClick::create([
            'company_id' => $link->company_id,
            'link_id' => $link->id,
            'clicked_at' => now(),
            'ip_hash' => hash_hmac('sha256', (string) $request->ip(), config('app.key')),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
        ]);
    }
}
