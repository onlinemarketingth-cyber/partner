<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TrackedLinkGroup;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTrackedLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreAffiliateLeadRequest;
use App\Http\Resources\PublicAffiliateLinkContextResource;
use App\Models\AffiliateLink;
use App\Models\Company;
use App\Services\Link\TrackedLinkService;
use App\Services\Referral\AffiliateLeadCaptureService;
use Illuminate\Http\JsonResponse;

/**
 * ADR-011 Section 4 (TASK-032) — POST /api/v1/public/affiliate-leads/{token},
 * PUBLIC, unauthenticated (rate-limited — see routes/api.php). The
 * first unauthenticated WRITE endpoint in this codebase — flagged
 * explicitly in ADR-011 and CLAUDE.md Section 3, not built silently.
 *
 * Response shape is deliberately generic either way (no Client/Referral
 * IDs or internal state ever echoed back to an anonymous caller) — this
 * is a public form, not an authenticated API a legitimate integration
 * would parse structured data from.
 *
 * show() (TASK-033 gap-fill) is the read counterpart the landing page
 * calls on mount, same {token} path, GET instead of POST — see
 * PublicAffiliateLinkContextResource for what it discloses.
 */
class AffiliateLeadCaptureController extends Controller
{
    use ResolvesTrackedLink;

    public function show(string $token): PublicAffiliateLinkContextResource
    {
        // TASK-235 — short code or legacy token, same as the redirect.
        $link = $this->resolveViaTrackedLink(
            $token,
            TrackedLinkGroup::Affiliate,
            AffiliateLink::class,
            request(),
            app(TrackedLinkService::class),
        ) ?? AffiliateLink::withoutGlobalScopes()->where('token', $token)->first();
        $link?->loadMissing(['company', 'agent', 'product']);

        abort_if(! $link, 404);

        // TASK-183 §3.5 — a closed tenant's landing page must not render. Same
        // 404 as an unknown token, so this cannot be used to probe which
        // companies are suspended (§3.4). Note that `with('company')` would
        // NOT have answered this: that relation applies SoftDeletingScope and
        // reads no `is_active`, so it returns null for a deleted company and
        // the full row for a deactivated one — hence the explicit predicate.
        abort_unless(Company::isOperationalById($link->company_id), 404);

        return new PublicAffiliateLinkContextResource($link);
    }

    public function store(StoreAffiliateLeadRequest $request, string $token, AffiliateLeadCaptureService $service): JsonResponse
    {
        $link = app(TrackedLinkService::class)->resolveTarget($token, TrackedLinkGroup::Affiliate, AffiliateLink::class)
            ?? AffiliateLink::withoutGlobalScopes()->where('token', $token)->first();

        abort_if(! $link, 404);

        // TASK-183 §3.5 — refuse BEFORE the honeypot branch and before
        // capture(), so a closed tenant gains no Client and no Referral from
        // an unauthenticated write. 404 (not the honeypot's fake success and
        // not capture()'s generic 422) because the link itself is dead here,
        // which is the same fact as an unknown token immediately above.
        abort_unless(Company::isOperationalById($link->company_id), 404);

        // Honeypot (human-approved, ADR-011/TASK-032 design question):
        // a filled hp_field means a bot filled EVERY input blindly,
        // including the one real visitors never see (hidden via CSS in
        // the TASK-033 frontend form). Return the SAME success shape a
        // genuine submission gets — never a 422/error — so a bot never
        // learns it was caught, but silently skip creating anything.
        if (! empty($request->validated('hp_field'))) {
            return response()->json(['message' => 'Thank you — we will be in touch shortly.']);
        }

        $referral = $service->capture($link, $request->validated());

        // Deliberately generic (never "this agent is inactive" / "no
        // product configured") — a public error message should not leak
        // internal BR-1/config state about someone else's business.
        abort_if(! $referral, 422, 'Unable to process this request at this time.');

        return response()->json(['message' => 'Thank you — we will be in touch shortly.']);
    }
}
