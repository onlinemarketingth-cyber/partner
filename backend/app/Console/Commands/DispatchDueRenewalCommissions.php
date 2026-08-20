<?php

namespace App\Console\Commands;

use App\Enums\CommissionEarnedVia;
use App\Enums\PaymentStatus;
use App\Models\CommissionLedger;
use App\Models\Product;
use App\Models\Referral;
use App\Services\Commission\CommissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// TASK-024 (ADR-004 pattern reused, ADR-006): runs daily
// (routes/console.php). For each referral whose next_renewal_date is
// now due: claims it by clearing/advancing next_renewal_date INSIDE
// the same DB::transaction() that reads it, BEFORE the notification-
// free ledger write — same "claim commits before the side effect" idea
// as DispatchDueFollowUpReminders, adapted here to guard against a
// double-fire if this command somehow runs twice concurrently.
class DispatchDueRenewalCommissions extends Command
{
    protected $signature = 'commissions:dispatch-due-renewals';

    protected $description = 'Record a renewal-year commission_ledger entry for every referral whose next_renewal_date is now due (TASK-024)';

    public function handle(CommissionService $commissionService): int
    {
        // withoutGlobalScopes() — same platform-wide background-job
        // rationale as DispatchDueFollowUpReminders: this never responds
        // to a client request, so TenantScope's cross-tenant-leak concern
        // (Section 5) doesn't apply here.
        // whereDate() (not where('next_renewal_date', '<=', ...)) —
        // deliberate fix, see bug note below the class doc: Eloquent's
        // 'date' cast on Referral::$casts still SERIALIZES for storage
        // using the connection's full getDateFormat() ('Y-m-d H:i:s'),
        // so the column actually holds e.g. '2027-07-15 00:00:00', not
        // a bare '2027-07-15'. A raw where('<=', 'Y-m-d') string
        // comparison against that value is always false (the longer
        // datetime string sorts AFTER the bare date string
        // lexicographically), so the query silently never matched
        // anything. whereDate() wraps the column in a DATE()-equivalent
        // extraction so only the date portion is ever compared,
        // regardless of the stored time component.
        $dueReferralIds = Referral::withoutGlobalScopes()
            ->whereNotNull('next_renewal_date')
            ->whereDate('next_renewal_date', '<=', now()->toDateString())
            ->pluck('id');

        $dispatched = 0;

        foreach ($dueReferralIds as $referralId) {
            DB::transaction(function () use ($referralId, $commissionService, &$dispatched) {
                // Re-fetch + lock inside the transaction — between the
                // pluck() above and this point, another process could
                // have already claimed (or cleared) this referral.
                $referral = Referral::withoutGlobalScopes()
                    ->whereKey($referralId)
                    ->whereNotNull('next_renewal_date')
                    ->whereDate('next_renewal_date', '<=', now()->toDateString())
                    ->lockForUpdate()
                    ->first();

                if (! $referral) {
                    return;
                }

                // next_renewal_date is only ever stamped by CommissionService
                // right after the direct-sale row is created (BR-4), so this
                // row should always exist — but never crash a background job
                // over a data inconsistency; just stop retrying this
                // referral if it's somehow missing.
                $originalLedger = CommissionLedger::withoutGlobalScopes()
                    ->where('referral_id', $referral->id)
                    ->where('earned_via', CommissionEarnedVia::Direct->value)
                    ->first();

                if (! $originalLedger) {
                    $referral->update(['next_renewal_date' => null]);

                    return;
                }

                // Re-look up commission_rules by the ORIGINAL sale's
                // snapshot (product + agent's cert tier AT THE TIME of the
                // sale, BR-4) — this reads the CURRENT renewal rate for
                // that same rule, so an admin editing the rate later
                // affects future renewal cycles (BR-2), never past ones.
                // ADR-011/TASK-028 — must go through the SAME
                // product/category/company-default resolution
                // CommissionService::recordForReferral() uses, not a
                // bare product_id lookup: the original sale may well
                // have been priced via a category or company-wide
                // default rule (no product_id on that row at all).
                // ADR-035 — resolveCommissionRule() no longer takes a
                // cert tier: the original sale's cert_tier_id_at_time
                // stays on the ledger row as a historical snapshot (BR-4
                // immutability), it just doesn't feed rate resolution
                // anymore.
                $originalProduct = Product::withoutGlobalScopes()->find($originalLedger->product_id);
                $rule = $originalProduct
                    ? $commissionService->resolveCommissionRule($originalProduct)
                    : null;

                if (! $rule || ! $rule->renewal_rate_type) {
                    // The renewal rate was removed (or never existed) on
                    // the CURRENT rule — leave next_renewal_date untouched
                    // (the claim is never taken) so a human fixing the
                    // config still gets this cycle recorded on a later
                    // run, instead of it being silently lost.
                    return;
                }

                $productPriceSatang = $referral->product->price_satang;
                $amountSatang = $commissionService->computeAmount($rule->renewal_rate_type, $rule->renewal_rate_value, $productPriceSatang);

                CommissionLedger::create([
                    'company_id' => $referral->company_id,
                    'agent_id' => $referral->agent_id,
                    'referral_id' => $referral->id,
                    'cert_tier_id_at_time' => $originalLedger->cert_tier_id_at_time,
                    'product_id' => $originalLedger->product_id,
                    'rate_type_applied' => $rule->renewal_rate_type,
                    'rate_applied' => $rule->renewal_rate_value,
                    'amount_satang' => $amountSatang,
                    'payment_status' => PaymentStatus::Pending,
                    'paid_at' => null,
                    'earned_via' => CommissionEarnedVia::Renewal,
                ]);

                // renewal_recurs (admin-configurable per rule, ADR-006
                // Round 2): advance by one more year if it repeats, or
                // clear (stop firing forever) if it was a one-time renewal.
                $referral->update([
                    'next_renewal_date' => $rule->renewal_recurs
                        ? $referral->next_renewal_date->copy()->addYear()->toDateString()
                        : null,
                ]);

                $dispatched++;
            });
        }

        $this->info("Dispatched {$dispatched} renewal commission(s).");

        return self::SUCCESS;
    }
}
