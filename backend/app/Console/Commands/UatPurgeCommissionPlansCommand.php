<?php

namespace App\Console\Commands;

use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\User;
use App\Services\Platform\CompanyRemovalService;
use Illuminate\Console\Command;

/**
 * Removes exactly what `uat:seed-commission-plans` created, and refuses to
 * remove anything else.
 *
 * ── WHY IT IS A SEPARATE COMMAND, AND WHY IT IS THIS NARROW ──
 *
 * The seeder runs against the live deployment, which means a delete command
 * has to live next to it — UAT tenants that cannot be removed are not UAT
 * tenants, they are permanent clutter in a real company switcher. But a
 * delete command on production is also the most dangerous thing in this
 * repository, so this one is built to be boring:
 *
 *   · it selects on `slug LIKE 'uat-plan-%'` and nothing else;
 *   · it names every company and every row count before it does anything;
 *   · it requires the operator to type the word, not press y;
 *   · it refuses outright if the selection is empty, rather than reporting
 *     success for having deleted nothing.
 *
 * BR-4 says a commission_ledger row may never be edited or deleted, and the
 * model enforces that with a `deleting` hook that always throws. That rule is
 * about the integrity of a real company's payout history, and these rows
 * belong to companies that never paid anybody — so the delete goes through
 * the database rather than the model, deliberately and only here, scoped to
 * company ids this command has already proved are UAT tenants. The only other
 * caller is the company screen's delete for a company flagged บริษัททดสอบ —
 * see CompanyRemovalService, which both now share.
 */
class UatPurgeCommissionPlansCommand extends Command
{
    protected $signature = 'uat:purge-commission-plans {--force : Skip the typed confirmation}';

    protected $description = 'Delete the disposable UAT tenants created by uat:seed-commission-plans, and nothing else';

    /** What the operator has to type. Not "yes" — the word names the thing being destroyed. */
    private const CONFIRMATION = 'PURGE UAT';

    public function handle(CompanyRemovalService $removal): int
    {
        $companies = Company::withoutGlobalScopes()
            ->withTrashed()
            ->where('slug', 'like', UatSeedCommissionPlansCommand::SLUG_PREFIX.'%')
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            // Said as a failure, not a success. "Nothing to do" and "I deleted
            // your UAT tenants" must not look the same in a runbook.
            $this->error('No UAT company found (slug '.UatSeedCommissionPlansCommand::SLUG_PREFIX.'*). Nothing was deleted.');

            return self::FAILURE;
        }

        $ids = $companies->pluck('id')->all();

        $this->line('These companies and everything under them will be permanently deleted:');
        $this->newLine();

        $rows = [];
        foreach ($companies as $company) {
            $rows[] = [
                $company->id,
                $company->name,
                $company->slug,
                User::withoutGlobalScopes()->withTrashed()->where('company_id', $company->id)->count(),
                CommissionLedger::withoutGlobalScopes()->where('company_id', $company->id)->count(),
            ];
        }

        $this->table(['id', 'name', 'slug', 'users', 'ledger rows'], $rows);
        $this->newLine();

        /*
         * The guard that matters. Everything above selected on the prefix, so
         * this can only fail if the prefix itself were ever loosened — which
         * is exactly the change that would be made carelessly, and exactly
         * the one nobody would notice at review.
         */
        foreach ($companies as $company) {
            if (! str_starts_with($company->slug, UatSeedCommissionPlansCommand::SLUG_PREFIX)) {
                $this->error("Refusing to run: company {$company->id} ({$company->slug}) is not a UAT tenant.");

                return self::FAILURE;
            }
        }

        if (! $this->option('force')) {
            $typed = (string) $this->ask('Type '.self::CONFIRMATION.' to confirm');

            if ($typed !== self::CONFIRMATION) {
                $this->warn('Not confirmed. Nothing was deleted.');

                return self::SUCCESS;
            }
        }

        /*
         * 2026-09-26 — the deletion itself moved to CompanyRemovalService::wipe(),
         * shared with the company screen's ลบบริษัท for test companies. One
         * routine that deletes a tenant rather than two that drift apart; the
         * ordering notes that used to live here (RESTRICT references, the rank
         * pointer, forceDelete over delete) live there now.
         *
         * What stays HERE is this command's own gate: the `uat-plan-` prefix
         * check above and the typed confirmation. The screen has its own gate
         * — the บริษัททดสอบ flag and the company name typed back.
         */
        foreach ($companies as $company) {
            $removal->wipe($company);
        }

        $this->info('Deleted '.count($ids).' UAT compan'.(count($ids) === 1 ? 'y' : 'ies').'.');

        /*
         * Users are the one thing company_id does NOT cascade — the column is
         * nullable, so the FK is nullOnDelete. Reported rather than deleted:
         * a row whose company just vanished is a real orphan an operator
         * should see, and guessing which orphans were ours is how a purge
         * command ends up deleting a person.
         */
        $orphans = User::withoutGlobalScopes()->withTrashed()->where('email', 'like', 'uat+'.UatSeedCommissionPlansCommand::SLUG_PREFIX.'%@uat.invalid')->count();

        if ($orphans > 0) {
            $this->warn("{$orphans} UAT agent account(s) remain (users are not cascaded). They have no company, no usable password and an unreachable .invalid address.");
            $this->line('Remove them from the user management screen if you want them gone.');
        }

        return self::SUCCESS;
    }
}
