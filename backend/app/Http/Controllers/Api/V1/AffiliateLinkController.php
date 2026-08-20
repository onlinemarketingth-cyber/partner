<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Referral\StoreAffiliateLinkRequest;
use App\Http\Resources\AffiliateLinkResource;
use App\Models\AffiliateLink;
use App\Services\Referral\AffiliateLinkService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// ADR-011/TASK-032 — Section 5 rule 4: index narrows to the Agent's own
// links, same shape as ReferralController/ClientController. No update()
// — a link's token/agent/product are immutable once minted (revoking is
// destroy()); "list clicks/conversions" is exposed via withCount() on
// index/show rather than a separate endpoint, since AffiliateLinkClick
// rows are internal detail, not something the task spec asked to
// paginate individually.
class AffiliateLinkController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(AffiliateLink::class, 'affiliate_link');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AffiliateLink::withCount(['clicks', 'attributedReferrals']);

        if ($request->user()->isAgent()) {
            $query->where('agent_id', $request->user()->id);
        }

        return AffiliateLinkResource::collection($query->latest()->paginate());
    }

    public function store(StoreAffiliateLinkRequest $request, AffiliateLinkService $service): AffiliateLinkResource
    {
        $link = $service->create($request->validated(), $request->user());

        return new AffiliateLinkResource($link->loadCount(['clicks', 'attributedReferrals']));
    }

    public function show(AffiliateLink $affiliateLink): AffiliateLinkResource
    {
        return new AffiliateLinkResource($affiliateLink->loadCount(['clicks', 'attributedReferrals']));
    }

    /**
     * TASK-236 — REVOKE, not delete.
     *
     * This used to call `->delete()`. `affiliate_link_clicks.link_id`
     * cascades, so an agent tidying up a link they no longer used silently
     * destroyed every click it had ever recorded — the only real per-click
     * history in the whole application, and the company's reporting quietly
     * changed shape underneath them with nothing to warn anyone.
     *
     * It was also the only one of the six link tables that hard-deleted at
     * all; the other five have set a `revoked_at` all along. The response
     * stays 204 so no caller has to change.
     */
    public function destroy(AffiliateLink $affiliateLink): Response
    {
        $affiliateLink->update(['revoked_at' => now()]);
        $affiliateLink->trackedLink()->withoutGlobalScopes()->first()?->update(['revoked_at' => now()]);

        return response()->noContent();
    }
}
