<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WithdrawalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\MarkWithdrawalTransferredRequest;
use App\Http\Requests\Commission\RejectWithdrawalRequestRequest;
use App\Http\Requests\Commission\StoreCompanyPayoutBatchRequest;
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

        $query = $this->visibleTo($request)
            ->with(['agent', 'decidedBy', 'items'])
            ->latest('id');

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
     * The rows THIS caller may see — the one definition, used by the list
     * and by the summary above it.
     *
     * 2026-09-15 — extracted when the queue gained a summary band. Two
     * copies of this scoping is the shape where a Super Admin's header
     * company applies to the list and not to the totals printed over it:
     * the screen would then show one tenant's rows under another tenant's
     * money, which is the exact defect CompanyScopeFilter was added to fix
     * in the first place.
     *
     * @return \Illuminate\Database\Eloquent\Builder<CommissionWithdrawalRequest>
     */
    private function visibleTo(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = CommissionWithdrawalRequest::query();

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

        $user = $request->user();

        if (! $user->isSuperAdmin() && ! $user->isCompanyAdmin()) {
            $query->where('agent_id', $user->id);
        }

        return $query;
    }

    /**
     * 2026-09-15 — HOW MUCH MONEY IS SITTING IN EACH STEP.
     *
     * Owner: "มันดูแล้วไม่เข้าใจทันทีว่า User เข้ามาต้องทำอะไร ดูอะไรบ้าง".
     *
     * The queue loads ONE status at a time, so the screen could say what was
     * in front of the reader and nothing about the two steps either side of
     * it — four boxes of chrome and no number anywhere. This is the band that
     * fixes that: one figure per step, so "where is the money right now" is
     * answered before anything has to be clicked.
     *
     * ── NO DATE WINDOW, DELIBERATELY ──
     *
     * Every figure counts exactly the rows its own tab lists. A "this month"
     * total on โอนแล้ว would read better and disagree with the list under it
     * the moment anybody opened that tab — and a headline figure that does
     * not match the rows beneath it is worse than no figure.
     *
     * @return array{data: array<string, array{count: int, satang: int}>}
     */
    public function summary(Request $request): array
    {
        $this->authorize('viewAny', CommissionWithdrawalRequest::class);

        $rows = $this->visibleTo($request)
            ->selectRaw('status, COUNT(*) as row_count, COALESCE(SUM(amount_satang), 0) as total_satang')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $summary = [];

        // EVERY status, present or not. A missing key would make the screen
        // choose between rendering nothing and inventing a zero; zero is the
        // true answer here and the server is the one that knows it.
        foreach (WithdrawalStatus::cases() as $status) {
            $row = $rows->get($status->value);

            $summary[$status->value] = [
                'count' => (int) ($row->row_count ?? 0),
                'satang' => (int) ($row->total_satang ?? 0),
            ];
        }

        return ['data' => $summary];
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
         *
         * 2026-09-15 (ครั้งที่สอง) — that check used to be written out here,
         * which put it OUTSIDE the row lock the service takes. It moved into
         * CommissionWithdrawalService::payOutSettling() so the comparison and
         * the write happen under the same lock, and so the batch door cannot
         * drift from this one.
         */
        $payout = $service->payOutSettling(
            $agent,
            $request->integer('expected_total_satang'),
            $request->user(),
        );

        return new CommissionWithdrawalRequestResource($payout->load(['agent', 'decidedBy', 'items']));
    }

    /**
     * 2026-09-15 (ครั้งที่สอง) — POST /commission-withdrawals/payout-batch.
     *
     * The same act as payOut() above for everybody ticked on the ตั้งจ่าย
     * table. All or nothing: see CommissionWithdrawalService::payOutMany() for
     * why a per-row loop of the single endpoint is the one shape this must not
     * have.
     *
     * Duplicate ids are rejected here rather than deduplicated. A list naming
     * the same payee twice is a screen that lost track of its own selection,
     * and paying them once "because that is obviously what was meant" hides
     * that — while paying twice would raise a second payout against a balance
     * the first one already reserved.
     */
    public function payOutBatch(
        StoreCompanyPayoutBatchRequest $request,
        CommissionWithdrawalService $service,
    ): JsonResponse {
        $rows = $request->validated('payees');
        $ids = array_map(static fn (array $row) => (int) $row['agent_id'], $rows);

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'payees' => 'มีตัวแทนซ้ำกันในรายการที่เลือก — กรุณารีเฟรชหน้าจอแล้วเลือกใหม่',
            ]);
        }

        $payees = [];

        foreach ($rows as $index => $row) {
            // withoutGlobalScopes() for the same reason as payOut(): a Super
            // Admin resolves people in any company, and the policy below is
            // what decides whether they may act for this one.
            $agent = User::withoutGlobalScopes()->findOrFail((int) $row['agent_id']);

            /*
             * Asked for EVERY row before a single payout is written. A policy
             * failure throws a 403, which is not one of the ValidationExceptions
             * the transaction rolls back cleanly — so the one that would be
             * refused must be found before the transaction opens, not during.
             */
            $this->authorize('raise', [CommissionWithdrawalRequest::class, $agent]);

            $payees[$index] = [
                'agent' => $agent,
                'expected_total_satang' => (int) $row['expected_total_satang'],
            ];
        }

        $payouts = $service->payOutMany($payees, $request->user());

        // ->each->load(), not ->load(): payOutMany answers with a plain
        // Support collection (it is built by hand from the loop), and only an
        // Eloquent collection knows how to eager-load across its members.
        $payouts->each->load(['agent', 'decidedBy', 'items']);

        // 201 explicitly. A single JsonResource infers it from the model being
        // recently created; a collection does not, and the two doors answering
        // the same act with different status codes is the kind of difference
        // that is only ever discovered by the client that broke on it.
        return CommissionWithdrawalRequestResource::collection($payouts)
            ->response()
            ->setStatusCode(201);
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
