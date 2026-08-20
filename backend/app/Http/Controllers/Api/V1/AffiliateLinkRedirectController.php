<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TrackedLinkGroup;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTrackedLink;
use App\Http\Controllers\Controller;
use App\Models\AffiliateLink;
use App\Models\Company;
use App\Services\Link\TrackedLinkService;
use App\Services\Referral\AffiliateLinkClickService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ADR-011 Section 4 (TASK-032) — GET /l/{token}, PUBLIC, unauthenticated
 * (registered outside auth:sanctum, rate-limited — see routes/api.php).
 * `token` is an opaque 64-char random string, same "never enumerable"
 * treatment as SalesMaterialShareLinkController::show(). Logs the click
 * then redirects to the Agent Portal frontend's own public landing page
 * for this token (TASK-033 builds that page — it POSTs to
 * /api/v1/public/affiliate-leads/{token} when the visitor submits the
 * lead-capture form).
 */
class AffiliateLinkRedirectController extends Controller
{
    use ResolvesTrackedLink;

    public function show(string $token, Request $request, AffiliateLinkClickService $clickService): RedirectResponse
    {
        // TASK-235 — `{token}` is now EITHER the short code from
        // /l/W2K7NRQ4TB or the original 64-character token. Both keep
        // working; only the short one records a tracked visit, because only
        // it has a tracked link behind it.
        $link = $this->resolveViaTrackedLink(
            $token,
            TrackedLinkGroup::Affiliate,
            AffiliateLink::class,
            $request,
            app(TrackedLinkService::class),
        ) ?? AffiliateLink::withoutGlobalScopes()->where('token', $token)->first();

        abort_if(! $link, 404);

        /*
         * TASK-183 §3.5 — a closed tenant's marketing links stop redirecting.
         *
         * Refused BEFORE $clickService->record(): a click on a dead link is
         * not analytics worth keeping, and recording it would write a row into
         * a deactivated company's data on an unauthenticated request. 404, the
         * same answer an unknown token gets one line above, so the visitor
         * cannot tell "no such link" from "that company is switched off"
         * (§3.4).
         */
        abort_unless(Company::isOperationalById($link->company_id), 404);

        $clickService->record($link, $request);

        $frontendUrl = rtrim(config('services.agent_portal.frontend_url'), '/');

        return redirect()->away("{$frontendUrl}/l/{$token}");
    }
}
