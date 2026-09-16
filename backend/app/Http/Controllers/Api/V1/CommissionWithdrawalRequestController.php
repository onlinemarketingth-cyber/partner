<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WithdrawalSource;
use App\Enums\WithdrawalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\MarkWithdrawalsTransferredRequest;
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
use App\Support\CsvCell;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * @return Builder<CommissionWithdrawalRequest>
     */
    private function visibleTo(Request $request): Builder
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
                'payees' => 'มีสมาชิกซ้ำกันในรายการที่เลือก — กรุณารีเฟรชหน้าจอแล้วเลือกใหม่',
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

    /**
     * 2026-09-16 — a whole round of transfers recorded in one press.
     *
     * Accounting transfers in rounds and reports back in batches, so the screen
     * takes a batch: tick the rows the bank confirmed, type the reference once,
     * press once. See MarkWithdrawalsTransferredRequest for why one reference
     * covers the batch, and markManyTransferred() for why it is all or nothing.
     *
     * Duplicate ids are refused rather than deduplicated, exactly as
     * payOutBatch() refuses duplicate payees: a list naming the same request
     * twice is a screen that lost track of its own selection, and quietly
     * settling it once hides that.
     */
    public function markTransferredBatch(
        MarkWithdrawalsTransferredRequest $request,
        CommissionWithdrawalService $service,
    ): AnonymousResourceCollection {
        $ids = array_map('intval', $request->validated('withdrawal_request_ids'));

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'withdrawal_request_ids' => 'มีรายการซ้ำกันในสิ่งที่เลือก — กรุณารีเฟรชหน้าจอแล้วเลือกใหม่',
            ]);
        }

        /*
         * Resolved through visibleTo() rather than by id alone. `exists:` in
         * the form request only proves the row is in the table — it says
         * nothing about whose company it belongs to, and this endpoint takes a
         * list of ids straight from a request body. A Company Admin naming
         * another tenant's request id must get "not found", not a 403 that
         * confirms it exists.
         */
        $requests = $this->visibleTo($request)
            ->whereIn('id', $ids)
            ->with(['agent', 'items'])
            ->get();

        if ($requests->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'withdrawal_request_ids' => 'มีบางรายการที่ไม่พบหรือไม่มีสิทธิ์ — กรุณารีเฟรชหน้าจอแล้วเลือกใหม่',
            ]);
        }

        // Every row's policy asked BEFORE the transaction opens, for the same
        // reason as payOutBatch(): a 403 is not a ValidationException and does
        // not roll back cleanly from inside one.
        foreach ($requests as $withdrawal) {
            $this->authorize('decide', $withdrawal);
        }

        $settled = $service->markManyTransferred(
            $requests->all(),
            $request->user(),
            $request->validated('transfer_reference'),
        );

        $settled->each->load(['agent', 'decidedBy', 'items']);

        return CommissionWithdrawalRequestResource::collection($settled);
    }

    /**
     * ═══ 2026-09-16 — รายงานการจ่าย: THE RECORD, NOT THE WORK ═══
     *
     * Owner: "หน้าเดิมเป็นสรุปรายการ เป็น Log ที่โอนแล้ว รอโอนโดยบัญชี Filter ได้
     * Export เป็น CSV ได้ตามที่ Filter".
     *
     * index() answers "what is waiting for me" — one status, twenty rows, newest
     * first, for a screen with buttons on it. This answers a different question:
     * "show me every payout that matches these conditions, including the ones
     * nothing will ever happen to again". Hence the filters index() does not
     * have — several statuses at once, source, a date window, a payee name —
     * and hence the totals, which index() has no use for.
     *
     * ── WHY THE DATE WINDOW HAS TO SAY WHICH DATE ──
     *
     * A payout has two of them and they answer different questions. "How much
     * did we pay out in September" means transferred_at; "how much was asked for
     * in September" means created_at, and a request raised in August and
     * transferred in September belongs to both answers, once each. Picking one
     * silently would make the report right for one reader and quietly wrong for
     * the other, so `date_basis` is explicit and defaults to the transfer date —
     * the question this page is usually opened to answer.
     *
     * A row with no transferred_at simply falls outside a transferred_at window.
     * That is correct, not a gap: money that has not moved was not paid out in
     * any month.
     */
    public function report(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CommissionWithdrawalRequest::class);

        $query = $this->reportQuery($request)
            ->with(['agent', 'decidedBy', 'items']);

        return CommissionWithdrawalRequestResource::collection(
            $query->paginate($request->integer('per_page') ?: 50)->withQueryString()
        );
    }

    /**
     * The same filtered set the report page is looking at, summed.
     *
     * Served beside the list rather than derived from the loaded page: the list
     * is paginated, and a total computed from fifty visible rows would be
     * labelled "ตามตัวกรองนี้" while describing only the first page of it. The
     * CSV export reads the same query, so the figure on screen, the rows on
     * screen and the rows in the file are always the same set.
     *
     * @return array{data: array{total_satang: int, count: int, transferred_satang: int, transferred_count: int, outstanding_satang: int, outstanding_count: int, payee_count: int}}
     */
    public function reportSummary(Request $request): array
    {
        $this->authorize('viewAny', CommissionWithdrawalRequest::class);

        $rows = (clone $this->reportQuery($request))->get(['status', 'amount_satang', 'agent_id']);

        $transferred = $rows->where('status', WithdrawalStatus::Transferred);
        /*
         * "ยังไม่โอน" is the two OPEN states only. Rejected and cancelled rows
         * are in the list — somebody filtering for them wants to see them — but
         * they are not money waiting to go anywhere, and adding them to a figure
         * labelled "not transferred yet" would state a liability the company
         * does not have.
         */
        $outstanding = $rows->filter(fn ($row) => $row->status->isOpen());

        return ['data' => [
            'total_satang' => (int) $rows->sum('amount_satang'),
            'count' => $rows->count(),
            'transferred_satang' => (int) $transferred->sum('amount_satang'),
            'transferred_count' => $transferred->count(),
            'outstanding_satang' => (int) $outstanding->sum('amount_satang'),
            'outstanding_count' => $outstanding->count(),
            'payee_count' => $rows->pluck('agent_id')->unique()->count(),
        ]];
    }

    /**
     * The report as a CSV — exactly the rows the filters select, no more.
     *
     * Deliberately NOT the payout file that AgentCommissionSummaryController
     * exports. That one is a bank instruction: one row per PERSON, pending
     * money only, full account numbers, made to be acted on. This one is a
     * record: one row per REQUEST, every status, masked accounts, made to be
     * filed and reconciled. Two exports because they are two documents; giving
     * either one the other's shape would produce a file that is dangerous in
     * one direction and useless in the other.
     */
    public function reportExport(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', CommissionWithdrawalRequest::class);

        $rows = $this->reportQuery($request)->with(['agent', 'decidedBy', 'items'])->get();

        $filename = 'payout-report-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            // UTF-8 BOM — without it Excel renders the Thai headers and agent
            // names below as mojibake, which defeats the point of a file made
            // to be opened and read.
            echo "\xEF\xBB\xBF";

            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'วันที่ขอ/ตั้งจ่าย',
                'วันที่โอน',
                'ผู้รับ',
                'ที่มา',
                'ยอด (บาท)',
                'จำนวนรายการค่าแนะนำ',
                'ธนาคาร',
                'บัญชีรับเงิน',
                'ชื่อบัญชี',
                'สถานะ',
                'เลขอ้างอิงการโอน',
                'ผู้อนุมัติ',
                'เหตุผลที่ไม่อนุมัติ',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->created_at?->format('Y-m-d') ?? '',
                    $row->transferred_at?->format('Y-m-d') ?? '',
                    CsvCell::safe($row->agent?->name ?? ''),
                    CsvCell::safe(($row->source ?? WithdrawalSource::AgentRequest)->label()),
                    // BR-3 — integer satang everywhere upstream; divided by 100
                    // only here, at the file's display layer.
                    number_format((int) $row->amount_satang / 100, 2, '.', ''),
                    $row->items->count(),
                    CsvCell::safe($row->bank_name ?? ''),
                    // Masked here exactly as it is on screen. A record that is
                    // filed, mailed and forwarded is the last place a full
                    // account number should travel — and nothing this file is
                    // used for needs the digits back.
                    CsvCell::safe($row->maskedBankAccountNumber() ?? ''),
                    CsvCell::safe($row->bank_account_holder_name ?? ''),
                    CsvCell::safe($row->status->label()),
                    CsvCell::safe($row->transfer_reference ?? ''),
                    CsvCell::safe($row->decidedBy?->name ?? ''),
                    CsvCell::safe($row->rejection_reason ?? ''),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * ONE definition of "the rows this report is about", read by the list, the
     * totals and the CSV alike.
     *
     * Three callers, one query, on purpose: the whole promise of this page is
     * that the number at the top, the rows underneath it and the file that comes
     * out of it describe the same set. Three separately-written filter blocks is
     * how that promise is broken by a later edit to two of them.
     *
     * @return Builder<CommissionWithdrawalRequest>
     */
    private function reportQuery(Request $request): Builder
    {
        $validated = $request->validate([
            'statuses' => ['sometimes', 'array'],
            'statuses.*' => ['string'],
            'sources' => ['sometimes', 'array'],
            'sources.*' => ['string'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date'],
            'date_basis' => ['sometimes', 'nullable', 'in:transferred_at,created_at'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $query = $this->visibleTo($request)->latest('id');

        /*
         * Parsed against the enum and dropped if unknown, like index() does —
         * an unrecognised status must narrow to nothing the reader can see,
         * never widen to everything. An EMPTY list after parsing means the
         * caller asked only for statuses that do not exist, which is not the
         * same as asking for none.
         */
        if ($asked = $validated['statuses'] ?? null) {
            $statuses = array_values(array_filter(array_map(
                static fn ($value) => WithdrawalStatus::tryFrom((string) $value)?->value,
                $asked,
            )));

            $query->whereIn('status', $statuses ?: ['__none__']);
        }

        if ($asked = $validated['sources'] ?? null) {
            $sources = array_values(array_filter(array_map(
                static fn ($value) => WithdrawalSource::tryFrom((string) $value)?->value,
                $asked,
            )));

            $query->whereIn('source', $sources ?: ['__none__']);
        }

        $basis = $validated['date_basis'] ?? 'transferred_at';

        if ($from = $validated['date_from'] ?? null) {
            $query->whereDate($basis, '>=', $from);
        }

        if ($to = $validated['date_to'] ?? null) {
            $query->whereDate($basis, '<=', $to);
        }

        if ($needle = trim((string) ($validated['q'] ?? ''))) {
            /*
             * Matched against the agent's CURRENT name rather than anything
             * stored on the request: a payout has no name column, and the point
             * of typing a name here is to find the person you are thinking of
             * today. whereHas rather than a join so TenantScope on users still
             * applies to the subquery.
             */
            $query->whereHas('agent', fn ($q) => $q->where('name', 'like', '%'.$needle.'%'));
        }

        return $query;
    }
}
