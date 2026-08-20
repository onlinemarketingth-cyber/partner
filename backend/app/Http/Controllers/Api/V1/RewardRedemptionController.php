<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RedemptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Engagement\DecideRewardRedemptionRequest;
use App\Http\Requests\Engagement\StoreRewardRedemptionRequest;
use App\Http\Requests\Engagement\UpdateRewardRedemptionTrackingRequest;
use App\Http\Resources\RewardRedemptionResource;
use App\Models\RewardItem;
use App\Models\RewardRedemption;
use App\Services\Engagement\RewardRedemptionService;
use App\Support\CompanyScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// Agent-view IA item 1.5. index narrows to "own only" for Agent, same
// shape as CommissionLedgerController. No update()/destroy() — status
// only moves through decide() (see RewardRedemptionService's transition
// table), same deliberately-narrow-exception shape as
// CommissionLedgerController::markPaid().
class RewardRedemptionController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(RewardRedemption::class, 'reward_redemption');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = RewardRedemption::with(['user', 'rewardItem', 'decidedBy']);
        // TASK-209 — Super Admin's header company scope, applied in SQL.
        CompanyScopeFilter::apply($query, $request);

        if ($request->user()->isAgent()) {
            $query->where('user_id', $request->user()->id);
        }

        return RewardRedemptionResource::collection($query->latest('requested_at')->paginate());
    }

    public function store(StoreRewardRedemptionRequest $request, RewardRedemptionService $service): RewardRedemptionResource
    {
        $rewardItem = RewardItem::findOrFail($request->validated('reward_item_id'));
        $shippingData = $request->safe()->only(['shipping_recipient_name', 'shipping_phone', 'shipping_address']);
        $redemption = $service->requestRedemption($rewardItem, $request->user(), $shippingData);

        return new RewardRedemptionResource($redemption->load(['user', 'rewardItem']));
    }

    public function show(RewardRedemption $rewardRedemption): RewardRedemptionResource
    {
        return new RewardRedemptionResource($rewardRedemption->load(['user', 'rewardItem', 'decidedBy']));
    }

    /** GET /reward-redemptions/my-balance — Agent's current spendable points (BR-7 flag: currently = XP total, see RewardRedemptionService docblock). */
    public function myBalance(Request $request, RewardRedemptionService $service): JsonResponse
    {
        return response()->json([
            'available_points' => $service->calculateAvailablePoints($request->user()),
        ]);
    }

    /** POST /reward-redemptions/{reward_redemption}/decide — approve/reject/fulfill (Company Admin/Super Admin only, see RewardRedemptionPolicy::decide()). */
    public function decide(DecideRewardRedemptionRequest $request, RewardRedemption $rewardRedemption, RewardRedemptionService $service): RewardRedemptionResource
    {
        $newStatus = RedemptionStatus::from($request->validated('status'));
        $redemption = $service->decide($rewardRedemption, $newStatus, $request->user(), $request->validated('decision_note'));

        return new RewardRedemptionResource($redemption);
    }

    /**
     * PATCH /reward-redemptions/{reward_redemption}/tracking-number —
     * TASK-042 §2: plain Admin-editable field, any time after Approved,
     * deliberately not folded into decide() (see RewardRedemptionService
     * docblock — this is not a status-machine transition).
     */
    public function updateTrackingNumber(UpdateRewardRedemptionTrackingRequest $request, RewardRedemption $rewardRedemption, RewardRedemptionService $service): RewardRedemptionResource
    {
        $redemption = $service->updateTrackingNumber($rewardRedemption, $request->validated('tracking_number'));

        return new RewardRedemptionResource($redemption);
    }
}
