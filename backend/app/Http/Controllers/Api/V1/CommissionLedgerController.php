<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\BulkMarkCommissionPaidRequest;
use App\Http\Resources\CommissionLedgerResource;
use App\Models\CommissionLedger;
use App\Models\User;
use App\Services\Commission\CommissionPayoutService;
use App\Support\CompanyScopeFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

// BR-4 — read-only except markPaid(). No store()/update()/destroy():
// rows are only ever written by CommissionService (system-triggered at
// Complete Payment, see PipelineService), never via this Controller.
// Section 5 rule 4 — index narrows to the Agent's own earnings, same
// shape as ClientController/ReferralController.
class CommissionLedgerController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(CommissionLedger::class, 'commission_ledger');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        // `company` carries only the two columns CommissionLedgerResource's
        // `is_company_share` needs — loaded here so that flag costs one query
        // for the page instead of one per row.
        $query = CommissionLedger::with(['referral.client', 'agent', 'certTierAtTime', 'product', 'overrideSourceAgent', 'appliedPricePromotionAtTime', 'company:id,commission_house_user_id']);

        // TASK-209 — Super Admin's header company scope, applied in SQL.
        CompanyScopeFilter::apply($query, $request);

        // TASK-046 — the Admin's per-agent drill-down on the Commission
        // Summary page reuses this SAME endpoint rather than a new one
        // (identical auth gate, identical eager-loads, just a narrower
        // WHERE — same "same resource, different filter" reasoning
        // AgentCommissionSummaryController::export() uses next to its own
        // index()). The Agent's own forced self-filter below is
        // unconditional and always applied FIRST — a non-Agent-supplied
        // ?agent_id= can therefore never let an Agent see anyone else's
        // rows, even if they tamper with the query string client-side.
        if ($request->user()->isAgent()) {
            $query->where('agent_id', $request->user()->id);
        } elseif ($request->filled('agent_id')) {
            // No extra company_id/IDOR guard needed here: CommissionLedger
            // carries TenantScope (Section 5 rule 2), so a Company Admin
            // passing a foreign-company agent_id simply gets zero rows —
            // their query is already narrowed to their own company_id
            // before this filter is even applied.
            $query->where('agent_id', $request->integer('agent_id'));
        }

        // TASK-046 — same additive date-range/status filters as
        // AgentCommissionSummaryController, so the drill-down list an
        // Admin opens for one agent can match whatever range/status is
        // currently applied on the summary page above it.
        $validated = $request->validate([
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'payment_status' => ['sometimes', Rule::enum(PaymentStatus::class)],
        ]);

        if (isset($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (isset($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        if (isset($validated['payment_status'])) {
            $query->where('payment_status', $validated['payment_status']);
        }

        return CommissionLedgerResource::collection($query->latest()->paginate());
    }

    public function show(CommissionLedger $commissionLedger): CommissionLedgerResource
    {
        return new CommissionLedgerResource($commissionLedger->load(['referral.client', 'agent', 'certTierAtTime', 'product', 'overrideSourceAgent', 'appliedPricePromotionAtTime']));
    }

    /**
     * POST /commission-ledger/{commissionLedger}/mark-paid — one row.
     *
     * 2026-09-15 — the body of this method moved into
     * CommissionPayoutService. It used to carry the update, the audit row and
     * the notification inline, and the bulk endpoint below performs the same
     * act: leaving both here would be two implementations of "a commission
     * stops being owed", and the audit row — added months after the fact,
     * because a security review found this was the one money-moving action
     * nobody recorded — is exactly the kind of thing a second copy forgets.
     */
    public function markPaid(Request $request, CommissionLedger $commissionLedger, CommissionPayoutService $payouts): CommissionLedgerResource
    {
        $this->authorize('markPaid', $commissionLedger);

        return new CommissionLedgerResource(
            $payouts->markPaid($commissionLedger, $request->user(), $request->ip()),
        );
    }

    /**
     * POST /commission-ledger/mark-paid — everything one agent is owed.
     *
     * Owner: "จ่ายทั้งหมดของคนนี้". A payout run is one person and a page of
     * rows, and settling them one press at a time is a run that can stop
     * halfway with no record of where.
     *
     * ROUTED ABOVE the {commission_ledger} routes in api.php so "mark-paid"
     * is never read as a ledger id.
     *
     * Answers with what it actually did rather than the rows it did it to:
     * the screen reloads both the per-agent totals and the open drill-down
     * afterwards anyway, and a response carrying fifty rows the caller is
     * about to throw away is fifty rows of payload for nothing.
     *
     * @return array{data: array{batch_id: string, paid_count: int, paid_satang: int}}
     */
    public function bulkMarkPaid(BulkMarkCommissionPaidRequest $request, CommissionPayoutService $payouts): array
    {
        /*
         * withoutGlobalScopes() so a Super Admin can resolve a payee in any
         * company — the policy immediately below is what decides whether they
         * may, and a TenantScope 404 here would answer "no such person" to a
         * Super Admin for whom that is false.
         */
        $payee = User::withoutGlobalScopes()->findOrFail($request->integer('agent_id'));

        $this->authorize('markAgentPaid', [CommissionLedger::class, $payee]);

        return [
            'data' => $payouts->markAgentPaid(
                $payee,
                $request->input('date_from'),
                $request->input('date_to'),
                $request->integer('expected_total_satang'),
                $request->user(),
                $request->ip(),
            ),
        ];
    }
}
