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

    public function destroy(AffiliateLink $affiliateLink): Response
    {
        $affiliateLink->delete();

        return response()->noContent();
    }
}
