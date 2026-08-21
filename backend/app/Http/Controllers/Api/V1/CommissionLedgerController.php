<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CommissionLedgerResource;
use App\Models\AuditLog;
use App\Models\CommissionLedger;
use App\Services\Notification\NotificationService;
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
        $query = CommissionLedger::with(['referral.client', 'agent', 'certTierAtTime', 'product', 'overrideSourceAgent', 'appliedPricePromotionAtTime']);

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

    /** POST /commission-ledger/{commissionLedger}/mark-paid — the one allowed mutation (BR-4). */
    public function markPaid(Request $request, CommissionLedger $commissionLedger, NotificationService $notifier): CommissionLedgerResource
    {
        $this->authorize('markPaid', $commissionLedger);

        $before = $commissionLedger->payment_status;

        $commissionLedger->update([
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        /*
         * SECURITY AUDIT 2026-08-21 — THE ONE MONEY-MOVING ACTION IN THIS
         * APPLICATION WAS THE ONE ACTION NOBODY RECORDED.
         *
         * audit_logs' own migration says the table exists "for anything
         * affecting money, commission, status, certification, or
         * permissions", and this method is the single point where a
         * commission stops being owed and starts being paid. Role changes,
         * bank-account edits and national-id edits were all audited; this
         * was not. There was no way to answer "who authorised this payout"
         * — the only trace was paid_at, which says when and never who.
         *
         * The amount is recorded alongside the status deliberately. The
         * ledger row is immutable under BR-4, so the amount cannot drift
         * from what was approved — but an audit entry that forces the
         * reader to go and join another table to learn what was actually
         * paid is an audit entry people stop reading.
         *
         * Written after the update() and outside any transaction of its
         * own, matching every other AuditLog::create() in this codebase: a
         * logging failure must never roll back a payment that succeeded.
         */
        AuditLog::create([
            'company_id' => $commissionLedger->company_id,
            'actor_user_id' => $request->user()?->id,
            'action' => 'commission_ledger.marked_paid',
            'auditable_type' => CommissionLedger::class,
            'auditable_id' => $commissionLedger->id,
            'old_values' => ['payment_status' => $before?->value],
            'new_values' => [
                'payment_status' => PaymentStatus::Paid->value,
                'amount_satang' => $commissionLedger->amount_satang,
                'agent_user_id' => $commissionLedger->agent_id,
            ],
            'ip_address' => $request->ip(),
        ]);

        $commissionLedger->load(['referral.client', 'agent', 'certTierAtTime', 'product', 'overrideSourceAgent', 'appliedPricePromotionAtTime']);

        // TASK-053 Phase 2b — let the earning agent know their commission
        // was paid. BR-3: amount is satang; divide by 100 only here at
        // the display layer for the notification text.
        if ($commissionLedger->agent) {
            $baht = number_format($commissionLedger->amount_satang / 100, 2);
            $notifier->notify(
                $commissionLedger->agent,
                NotificationType::CommissionPaid,
                'ค่าคอมมิชชั่นจ่ายแล้ว',
                "จำนวน {$baht} บาท ถูกทำจ่ายเรียบร้อย",
                '/commission',
                ['commission_ledger_id' => $commissionLedger->id],
            );
        }

        return new CommissionLedgerResource($commissionLedger);
    }
}
