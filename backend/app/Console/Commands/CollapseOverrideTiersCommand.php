<?php

namespace App\Console\Commands;

use App\Models\CommissionOverrideRule;
use App\Models\Company;
use App\Models\Scopes\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * TASK-214 — collapse pre-TASK-214 team-leader rates that differed only by
 * the manager's cert tier.
 *
 * ═══ WHY THIS EXISTS ═══
 * Until 2026-08-19 a company could hold one override rate per cert tier
 * (Basic 1%, Intermediate 1.5%, High 2%) and they were legitimately
 * distinct rows — resolution read manager_cert_tier_id, so each manager
 * matched exactly one. The human then ruled that the rate must stop
 * depending on the tier ("ไม่ต้องผูก"), which makes those same rows
 * indistinguishable: they now all sit in the company-default scope, all
 * active, all matching. `resolveOverrideRule()` orders by effective_from
 * and takes the first, so the payout becomes whichever row the database
 * hands back — 1%, 1.5% or 2%, unpredictably, into an immutable ledger
 * (BR-4).
 *
 * ═══ WHY IT ASKS INSTEAD OF DECIDING ═══
 * Which rate survives is a business decision about real money (BR-7), and
 * nothing in this codebase is entitled to make it. "Keep the highest" is
 * as arbitrary as "keep the lowest"; both would be an invented number
 * wearing the costume of a migration. So this prints what exists, per
 * company, and asks. Companies whose tiers all carry the SAME rate need no
 * decision and collapse without a prompt — there is only one answer.
 *
 * Same interactive shape as ADR-035 §3's own migration command, chosen for
 * the identical reason: `tinker` is unavailable on the production plan, so
 * a console prompt is the only way to put the numbers in front of the
 * person who knows them at the moment they decide.
 *
 * Safe to run repeatedly — a company already down to one rate per scope is
 * reported as clean and left alone.
 */
class CollapseOverrideTiersCommand extends Command
{
    protected $signature = 'commission:collapse-override-tiers
                            {--dry-run : Show what collides without writing or prompting}';

    protected $description = 'Collapse legacy per-cert-tier team-leader override rates into one rate per scope (TASK-214)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // withoutGlobalScope(TenantScope) — a maintenance sweep across every
        // company, run from the console with no authenticated actor. Same
        // rationale as BackfillCatalogLinkedTaxonomyCommand's own sweep.
        $rules = CommissionOverrideRule::withoutGlobalScope(TenantScope::class)
            ->with('managerCertTier')
            ->orderBy('company_id')
            ->get();

        $clashes = $rules
            ->groupBy(fn (CommissionOverrideRule $r) => implode('|', [
                $r->company_id,
                $r->product_id ?? '-',
                $r->product_category_id ?? '-',
            ]))
            ->filter(fn (Collection $group) => $group->count() > 1 && $this->datesOverlapWithinGroup($group));

        if ($clashes->isEmpty()) {
            $this->info('No colliding team-leader override rates. Nothing to do.');

            return self::SUCCESS;
        }

        $companies = Company::withoutGlobalScopes()->pluck('name', 'id');
        $resolved = 0;
        $needsHuman = 0;

        foreach ($clashes as $key => $group) {
            [$companyId, $productId, $categoryId] = explode('|', (string) $key);
            $scope = $productId !== '-' ? "product #{$productId}"
                : ($categoryId !== '-' ? "category #{$categoryId}" : 'company-wide default');

            $this->newLine();
            $this->warn(sprintf(
                '%s (company #%s) — %s: %d rates active at once',
                $companies[(int) $companyId] ?? 'Unknown company',
                $companyId,
                $scope,
                $group->count(),
            ));

            foreach ($group as $rule) {
                $this->line(sprintf(
                    '   [%d] %s  tier: %-16s  from %s%s',
                    $rule->id,
                    str_pad($this->formatRate($rule), 12),
                    $rule->managerCertTier?->name ?? '(none)',
                    $rule->effective_from->toDateString(),
                    $rule->effective_to ? ' to '.$rule->effective_to->toDateString() : '',
                ));
            }

            $distinct = $group->map(fn (CommissionOverrideRule $r) => $r->rate_type->value.':'.$r->rate_value)->unique();

            // Every tier already carries the same rate — collapsing is not a
            // decision, it is arithmetic. Keep the oldest row so the
            // effective_from history stays truthful.
            if ($distinct->count() === 1) {
                $keep = $group->sortBy('effective_from')->first();
                $this->info("   → all identical ({$this->formatRate($keep)}) — keeping [{$keep->id}], deleting the rest");
                if (! $dryRun) {
                    $group->where('id', '!=', $keep->id)->each->delete();
                }
                $resolved++;

                continue;
            }

            if ($dryRun) {
                $this->error('   → rates DIFFER — a human must choose (run without --dry-run)');
                $needsHuman++;

                continue;
            }

            $choice = $this->choice(
                'Which rate should survive for this scope?',
                $group->mapWithKeys(fn (CommissionOverrideRule $r) => [
                    (string) $r->id => "[{$r->id}] {$this->formatRate($r)} (tier: ".($r->managerCertTier?->name ?? 'none').')',
                ])->all(),
            );

            // choice() returns the LABEL; recover the id from its prefix.
            $keepId = (int) trim(explode(']', ltrim($choice, '['))[0]);
            $group->where('id', '!=', $keepId)->each->delete();
            $this->info("   → kept [{$keepId}], deleted ".($group->count() - 1).' row(s)');
            $resolved++;
        }

        $this->newLine();
        if ($dryRun) {
            $this->info("Dry run: {$resolved} scope(s) would collapse automatically, {$needsHuman} need a decision.");

            return self::SUCCESS;
        }

        $this->info("Collapsed {$resolved} scope(s).");

        return self::SUCCESS;
    }

    /**
     * Two rows in the same scope only actually collide if their date ranges
     * overlap. A rate that ended in June and one that starts in August are
     * a legitimate history, not an ambiguity, and must not be offered up
     * for deletion.
     *
     * @param  Collection<int, CommissionOverrideRule>  $group
     */
    private function datesOverlapWithinGroup(Collection $group): bool
    {
        $rows = $group->values();

        foreach ($rows as $i => $a) {
            foreach ($rows->slice($i + 1) as $b) {
                $aEnd = $a->effective_to ?? now()->addCentury();
                $bEnd = $b->effective_to ?? now()->addCentury();

                if ($a->effective_from <= $bEnd && $b->effective_from <= $aEnd) {
                    return true;
                }
            }
        }

        return false;
    }

    private function formatRate(CommissionOverrideRule $rule): string
    {
        return $rule->rate_type->value === 'percentage'
            ? number_format($rule->rate_value / 100, 2).'%'
            : number_format($rule->rate_value / 100, 2).' THB';
    }
}
