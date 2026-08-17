<?php

namespace App\Services\Platform;

use App\Enums\AgentApprovalStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Referral;
use App\Models\User;
use App\Services\Referral\ClosedDealPredicate;
use Illuminate\Support\Collection;

/**
 * TASK-041 (4.2) — Cross-company report, Super-Admin-only (enforced in
 * PlatformReportController before this Service is ever called). Purely
 * read-only aggregate reporting over real data — Section 8 guardrail 2
 * ("never assume numbers"): every figure here is a live query, nothing
 * persisted or estimated.
 *
 * Companies are few in this prototype (Section 5's own framing — a
 * handful of tenants, not thousands), so a simple loop with one scoped
 * query per metric per company is chosen over a single groupBy() mega-
 * query: it stays trivially correct/readable at the cost of N+1-ish
 * query volume, which is an acceptable trade for this data size.
 */
class PlatformReportService
{
    public function buildReport(): Collection
    {
        return Company::query()
            ->orderBy('name')
            ->get()
            ->map(function (Company $company) {
                $agentCount = User::query()
                    ->where('company_id', $company->id)
                    ->where('role', UserRole::Agent)
                    ->count();

                $pendingAgentApprovals = User::query()
                    ->where('company_id', $company->id)
                    ->where('agent_approval_status', AgentApprovalStatus::Pending)
                    ->count();

                $totalReferrals = Referral::query()
                    ->where('company_id', $company->id)
                    ->count();

                // TASK-180 §3 (B2) — the ONE closed-deal answer (TASK-179
                // §3.1 / D4). This Service carried its own copy of
                // [complete_payment, ongoing_next_meeting] with a comment
                // admitting it was a duplicate of
                // ProductGradingService::SOLD_STAGES; both are gone. The
                // field is named `referrals_completed_payment`, and since
                // ADR-026 a deal advanced into จัดส่ง / นัดใช้บริการ /
                // ติดตามผล has very much completed payment — it was being
                // dropped from a cross-company report a Super Admin reads
                // as the platform's headline volume.
                //
                // BR-6: the explicit per-company narrowing stays, and must
                // stay — the predicate only narrows to "closed", and this
                // is the one endpoint whose caller is deliberately
                // un-tenant-scoped (Super-Admin-only, enforced in
                // PlatformReportController), so without this where() every
                // row here would be a cross-company total.
                $completedPaymentQuery = Referral::query()->where('referrals.company_id', $company->id);
                ClosedDealPredicate::apply($completedPaymentQuery);
                $referralsCompletedPayment = $completedPaymentQuery->count();

                $commissionPaidSatang = (int) CommissionLedger::query()
                    ->where('company_id', $company->id)
                    ->where('payment_status', PaymentStatus::Paid)
                    ->sum('amount_satang');

                $commissionPendingSatang = (int) CommissionLedger::query()
                    ->where('company_id', $company->id)
                    ->where('payment_status', PaymentStatus::Pending)
                    ->sum('amount_satang');

                return [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'agent_count' => $agentCount,
                    'pending_agent_approvals' => $pendingAgentApprovals,
                    'total_referrals' => $totalReferrals,
                    'referrals_completed_payment' => $referralsCompletedPayment,
                    // BR-3: satang integers, no float conversion here —
                    // dividing by 100 is strictly a UI display concern.
                    'commission_paid_satang' => $commissionPaidSatang,
                    'commission_pending_satang' => $commissionPendingSatang,
                ];
            });
    }
}
