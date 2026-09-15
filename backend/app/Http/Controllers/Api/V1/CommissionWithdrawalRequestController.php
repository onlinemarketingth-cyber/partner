<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WithdrawalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\MarkWithdrawalTransferredRequest;
use App\Http\Requests\Commission\RejectWithdrawalRequestRequest;
use App\Http\Requests\Commission\StoreCompanyPayoutRequest;
use App\Http\Requests\Commission\StoreWithdrawalRequestRequest;
use App\Http\Resources\CommissionWithdrawalRequestResource;
use App\Models\CommissionWithdrawalRequest;
use App\Models\User;
use App\Services\Commission\CommissionWithdrawalService;
use App\Support\CompanyScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Commission withdrawal — agent side (ask, watch, cancel) and admin side
 * (approve, reject, record the transfer). 2026-08-27.
 *
 * ONE controller for both audiences because it is one resource with one
 * lifecycle; the split is in the POLICY and in how index() scopes its query,
 * which is where a "who may see what" rule belongs. Two controllers would
 * mean two places to remember the tenant scoping.
 */
class CommissionWithdrawalRequestController extends Controller
{
    /**
     * Agents see their own requests. Admins see every request in their
     * company — that is the review queue.
     *
     * The role check decides the SCOPE, never the visibility of an
     * individual row: the Policy still owns view/decide, and TenantScope on
     * the model still owns the company boundary underneath both.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CommissionWithdrawalRequest::class);

        $user = $request->user();

        $query = CommissionWithdrawalRequest::query()
            ->with(['agent', 'decidedBy', 'items'])
            ->latest('id');

        /*
         * TASK-209 / ADR-038 — THE HEADER'S COMPANY SCOPE, MISSING UNTIL
         * 2026-09-04 (human-reported).
         *
         * TenantScope pins a Company Admin already, and deliberately does not
         * pin a Super Admin — so this queue handed a Super Admin every
         * company's withdrawal requests no matter which company the header
         * said they were working in. Real money, attributed to the wrong
         * tenant on screen, one "อนุมัติ" away.
         *
         * NARROWS ONLY (see CompanyScopeFilter): without ?company_id this is
         * still the deliberate read-across "ทุกบริษัท" view, and for anyone
         * who is not a Super Admin the parameter is ignored entirely rather
         * than trusted.
         */
        CompanyScopeFilter::apply($query, $request);

        if (! $user->isSuperAdmin() && ! $user->isCompanyAdmin()) {
            $query->where('agent_id', $user->id);
        }

        // The admin queue's default question is "what is waiting for me",
        // so an explicit ?status= narrows it. Validated against the enum
        // rather than passed through, so a typo is an empty filter the
        // caller can see rather than a silent full listing.
        if ($status = $request->query('status')) {
            $parsed = WithdrawalStatus::tryFrom((string) $status);
            $query->where('status', $parsed?->value ?? '__none__');
        }

        return CommissionWithdrawalRequestResource::collection($query->paginate(20));
    }

    /**
     * What the agent may ask for right now, plus the company's minimum.
     *
     * Both come from the server so the button's enabled/disabled state and
     * the check that would refuse the request are computed from the same
     * numbers — a balance worked out in the browser is a balance that can
     * disagree with the one that matters.
     */
    public function available(Request $request, CommissionWithdrawalService $service): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'available_satang' => $service->availableSatang($user),
            'min_withdrawal_satang' => $user->company?->min_withdrawal_satang,
            'payout_details_complete' => $user->hasCompletePayoutDetails(),
        ]);
    }

    public function store(
        StoreWithdrawalRequestRequest $request,
        CommissionWithdrawalService $service,
    ): CommissionWithdrawalRequestResource {
        $withdrawal = $service->request($request->user(), (int) $request->validated('amount_satang'));

        return new CommissionWithdrawalRequestResource($withdrawal->load(['agent', 'items']));
    }

    /**
     * 2026-09-15 — POST /commission-withdrawals/payout — "ตั้งจ่าย".
     *
     * The admin half of the same act. Owner: the company transfers through
     * its bank by hand and only confirms afterwards, so the decision and the
     * transfer are two different days and have to be two different states.
     *
     * This creates the payout already APPROVED (the press is the decision)
     * and leaves the commission ledger untouched until somebody records the
     * transfer — which is the button that replaces the old one-click
     * "จ่ายแล้ว" that settled the ledger and emailed the agent immediately.
     *
     * Answers with the created payout so the screen can show it landing in
     * the "รอโอน" tab rather than having to guess what happened.
     */
    public function payOut(
        StoreCompanyPayoutRequest $request,
        CommissionWithdrawalService $service,
    ): CommissionWithdrawalRequestResource {
        /*
         * withoutGlobalScopes() so a Super Admin can resolve an agent in any
         * company — the policy immediately below is what decides whether they
         * may, and a TenantScope 404 here would answer "no such person" to
         * somebody for whom that is false.
         */
        $agent = User::withoutGlobalScopes()->findOrFail($request->integer('agent_id'));

        $this->authorize('raise', [CommissionWithdrawalRequest::class, $agent]);

        /*
         * THE AMOUNT IS THE SERVER'S, NOT THE CALLER'S.
         *
         * A company payout settles the whole balance, so the only number in
         * the request is what the admin was SHOWN — and it is checked, not
         * used. Between the screen loading and the press, a sale can complete
         * and raise this figure; paying the new total would be paying an
         * amount nobody authorised, and BR-4 means the ledger rows it settles
         * cannot be un-settled.
         */
        $available = $service->availableSatang($agent);
        $expected = $request->integer('expected_total_satang');

        if ($available !== $expected) {
            throw ValidationException::withMessages([
                'expected_total_satang' => 'ยอดค้างจ่ายของคนนี้เปลี่ยนไปแล้ว ('
                    .number_format($available / 100, 2).' บาท ไม่ตรงกับ '
                    .number_format($expected / 100, 2).' บาท ที่แสดงอยู่) '
                    .'— กรุณารีเฟรชหน้าจอแล้วลองใหม่ ระบบยังไม่ได้ตั้งจ่ายใด ๆ',
            ]);
        }

        $payout = $service->payOut($agent, $available, $request->user());

        return new CommissionWithdrawalRequestResource($payout->load(['agent', 'decidedBy', 'items']));
    }

    public function show(CommissionWithdrawalRequest $commissionWithdrawalRequest): CommissionWithdrawalRequestResource
    {
        $this->authorize('view', $commissionWithdrawalRequest);

        return new CommissionWithdrawalRequestResource(
            $commissionWithdrawalRequest->load(['agent', 'decidedBy', 'items'])
        );
    }

    public function cancel(
        Request $request,
        CommissionWithdrawalRequest $commissionWithdrawalRequest,
        CommissionWithdrawalService $service,
    ): CommissionWithdrawalRequestResource {
        $this->authorize('cancel', $commissionWithdrawalRequest);

        return new CommissionWithdrawalRequestResource(
            $service->cancel($commissionWithdrawalRequest, $request->user())
        );
    }

    public function approve(
        Request $request,
        CommissionWithdrawalRequest $commissionWithdrawalRequest,
        CommissionWithdrawalService $service,
    ): CommissionWithdrawalRequestResource {
        $this->authorize('decide', $commissionWithdrawalRequest);

        return new CommissionWithdrawalRequestResource(
            $service->approve($commissionWithdrawalRequest, $request->user())
        );
    }

    public function reject(
        RejectWithdrawalRequestRequest $request,
        CommissionWithdrawalRequest $commissionWithdrawalRequest,
        CommissionWithdrawalService $service,
    ): CommissionWithdrawalRequestResource {
        $this->authorize('decide', $commissionWithdrawalRequest);

        return new CommissionWithdrawalRequestResource($service->reject(
            $commissionWithdrawalRequest,
            $request->user(),
            (string) $request->validated('rejection_reason'),
        ));
    }

    public function markTransferred(
        MarkWithdrawalTransferredRequest $request,
        CommissionWithdrawalRequest $commissionWithdrawalRequest,
        CommissionWithdrawalService $service,
    ): CommissionWithdrawalRequestResource {
        $this->authorize('decide', $commissionWithdrawalRequest);

        return new CommissionWithdrawalRequestResource($service->markTransferred(
            $commissionWithdrawalRequest,
            $request->user(),
            $request->validated('transfer_reference'),
        ));
    }
}
