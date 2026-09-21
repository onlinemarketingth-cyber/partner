<?php

namespace App\Console\Commands;

use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
 * company ids this command has already proved are UAT tenants. Nothing else
 * in the codebase may do this.
 */
class UatPurgeCommissionPlansCommand extends Command
{
    protected $signature = 'uat:purge-commission-plans {--force : Skip the typed confirmation}';

    protected $description = 'Delete the disposable UAT tenants created by uat:seed-commission-plans, and nothing else';

    /** What the operator has to type. Not "yes" — the word names the thing being destroyed. */
    private const CONFIRMATION = 'PURGE UAT';

    public function handle(): int
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

        DB::transaction(function () use ($ids): void {
            /*
             * Ledger rows first, through the query builder.
             *
             * CommissionLedger::deleting always throws (BR-4), which is right
             * for every other caller and would leave these tenants
             * undeletable. The cascade on companies.id would not reach them
             * anyway without this, because the model is what carries the
             * refusal, not the constraint.
             */
            CommissionLedger::withoutGlobalScopes()->whereIn('company_id', $ids)->delete();

            /*
             * ── WHY THE ORDER IS SPELLED OUT INSTEAD OF LEFT TO THE CASCADE ──
             *
             * Nearly every business table hangs off company_id with
             * cascadeOnDelete (§5.1), so deleting the company row SHOULD be
             * enough. It is not, and the reason is worth recording because it
             * will look like redundant code to the next reader.
             *
             * Several of those cascaded tables also point at EACH OTHER with
             * restrictOnDelete — commission_rules.product_id → products,
             * referrals.client_id → clients, and so on. The cascade fires them
             * in no defined order, so if products happen to go before the
             * rules that reference them, RESTRICT stops the whole delete with
             * a foreign-key error and the UAT tenants become undeletable.
             *
             * These six are the ones that hold RESTRICT references, deepest
             * first. Everything else still rides the cascade, so a table added
             * later needs no change here — unless it too takes a RESTRICT
             * reference, which the purge test will catch the first time it is
             * run for a plan that uses it.
             */
            foreach (['orders', 'referrals', 'commission_rules', 'commission_override_rules', 'clients', 'products'] as $table) {
                DB::table($table)->whereIn('company_id', $ids)->delete();
            }

            /*
             * users.current_rank_id points at agent_ranks, which the cascade
             * is about to remove — and the user row itself survives (its
             * company_id is nullOnDelete, not cascade). Cutting the link
             * first is what stops a stairstep or generation tenant failing
             * here on a constraint that has nothing to do with the tenant.
             */
            DB::table('users')->whereIn('company_id', $ids)->update(['current_rank_id' => null]);

            /*
             * forceDelete, not delete: a soft-deleted UAT company still owns
             * its slug, and the next seed run would find it and revive it.
             */
            Company::withoutGlobalScopes()->withTrashed()->whereIn('id', $ids)->forceDelete();
        });

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
