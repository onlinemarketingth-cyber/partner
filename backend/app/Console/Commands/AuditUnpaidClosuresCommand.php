<?php

namespace App\Console\Commands;

use App\Enums\CommissionEarnedVia;
use App\Enums\PipelineStage;
use App\Models\CommissionLedger;
use App\Models\Referral;
use App\Models\Scopes\TenantScope;
use Illuminate\Console\Command;

/**
 * TASK-215 — find sales that CLOSED but never paid the agent anything.
 *
 * ═══ WHY THIS EXISTS ═══
 * Found during UAT-016 (2026-08-19) on live dev data: three referrals had
 * reached Complete Payment on 13 ส.ค. and their ledger held only a
 * promotion bonus. No direct commission row. Ever.
 *
 * That is not a defect in CommissionService — it is its documented,
 * deliberate behaviour. When no commission_rule resolves for the product
 * (or the agent holds no cert tier), recordForReferral() returns null,
 * writes a Log::warning, and lets the pipeline advance. The sale must
 * never be blocked by a configuration gap. The cost of that choice is
 * that the ONLY evidence is a line in a log file nobody reads, and the
 * ledger is immutable (BR-4), so by the time anyone notices, the money
 * cannot be booked retroactively without an explicit business decision.
 *
 * TASK-213's readiness panel prevents the NEXT one. Nothing until now
 * could find the ones that already happened. This does.
 *
 * ═══ WHAT IT DELIBERATELY DOES NOT DO ═══
 * It does not write, repair, or back-date anything. Paying someone for a
 * sale that closed weeks ago at a rate that may not have existed then is
 * a business decision about real money (BR-7), and it collides with BR-4's
 * whole reason for existing. This command's output is the input to that
 * conversation, not a substitute for it.
 */
class AuditUnpaidClosuresCommand extends Command
{
    protected $signature = 'commission:audit-unpaid-closures
                            {--company= : Limit to one company_id}';

    protected $description = 'List closed sales that never produced a commission row for the selling agent (TASK-215)';

    public function handle(): int
    {
        // withoutGlobalScope(TenantScope) — a console sweep with no
        // authenticated actor, same as the other maintenance commands.
        $query = Referral::withoutGlobalScope(TenantScope::class)
            ->whereNotNull('submitted_at')
            ->with(['agent', 'client', 'product', 'company']);

        if ($companyId = $this->option('company')) {
            $query->where('company_id', (int) $companyId);
        }

        // "Closed" = has reached Complete Payment. The stage column holds
        // only the CURRENT stage, and post-sale journeys move past it
        // (Delivery, Follow-up...), so a plain where(current_stage) would
        // miss every referral that carried on afterwards. The pipeline log
        // is the durable record of having passed through.
        $closedIds = \DB::table('pipeline_stage_logs')
            ->where('to_stage', PipelineStage::CompletePayment->value)
            ->distinct()
            ->pluck('referral_id');

        $closed = $query->whereIn('id', $closedIds)->get();

        if ($closed->isEmpty()) {
            $this->info('No closed sales found. Nothing to audit.');

            return self::SUCCESS;
        }

        // A referral is "paid" if it produced a row the SELLING agent
        // earned. Overrides (the upline) and promotion bonuses do not
        // count — that is exactly the confusion this audit exists to cut
        // through: a promotion bonus on a sale with no commission looks
        // like money moved, and it did, to the wrong question.
        $paidReferralIds = CommissionLedger::withoutGlobalScope(TenantScope::class)
            ->whereIn('referral_id', $closed->pluck('id'))
            ->whereIn('earned_via', [CommissionEarnedVia::Direct->value, CommissionEarnedVia::Renewal->value])
            ->distinct()
            ->pluck('referral_id')
            ->all();

        $unpaid = $closed->whereNotIn('id', $paidReferralIds);

        if ($unpaid->isEmpty()) {
            $this->info("Checked {$closed->count()} closed sale(s) — every one produced a commission row for its agent.");

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error("{$unpaid->count()} closed sale(s) never paid the selling agent:");
        $this->newLine();

        $this->table(
            ['referral', 'company', 'agent', 'client', 'product', 'closed on', 'likely reason'],
            $unpaid->map(fn (Referral $r) => [
                $r->id,
                $r->company?->name ?? '—',
                $r->agent?->name ?? '—',
                $r->client?->name ?? '—',
                $r->product?->name ?? '—',
                optional($r->submitted_at)->toDateString() ?? '—',
                $this->likelyReason($r),
            ])->all(),
        );

        $this->newLine();
        $this->warn('Nothing has been changed. commission_ledger is immutable (BR-4) — whether any of these');
        $this->warn('should be compensated, and at what rate, is a business decision, not a repair script.');
        $this->line('Check the rate that was ACTIVE on the closing date, not the one configured today.');

        return self::SUCCESS;
    }

    /**
     * A best-effort hint, deliberately labelled "likely". The real cause
     * was whatever was true at closing time, and this can only inspect
     * what is true now — so it must never be phrased as a verdict.
     */
    private function likelyReason(Referral $r): string
    {
        if ($r->agent === null) {
            return 'agent record is gone';
        }

        if ($r->agent->highestPassedCertTier() === null) {
            return 'agent holds no passed cert tier';
        }

        return 'no commission_rule resolved (product/category/company) on that date';
    }
}
