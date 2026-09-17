<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-17 — EVERYTHING THAT POINTED AT A "SUPPLIER COMPANY" NOW POINTS AT
 * A SUPPLIER.
 *
 * Four links change owner, and one of them is the important one:
 *
 *   users.supplier_id                          a partner login belongs to a
 *                                              SUPPLIER, not to a tenant
 *   products.supplier_id                       who brought this product in
 *   supplier_settlement_ledger.supplier_id     who we owe
 *   supplier_withdrawal_requests.supplier_id   who we are paying
 *
 * ── WHY users.supplier_id AND NOT users.company_id ──
 *
 * The first cut gave a partner login `company_id = <the supplier company>`,
 * which is a sentence that stops making sense the moment a supplier is not a
 * company of ours. It also meant every supplier-facing query read
 * `$user->company_id` and MEANT something different by it than the other
 * fifty places in this codebase that read the same property. One property,
 * two meanings, decided by the reader's role, is how a tenant filter
 * eventually gets applied to the wrong side of a join.
 *
 * So: role `company_partner` carries `supplier_id` and `company_id = NULL`;
 * every other role carries `company_id` and `supplier_id = NULL`. The
 * exclusivity is enforced in StoreUserRequest/UpdateUserRequest rather than by
 * a CHECK constraint, because the error has to arrive as a validation message
 * on a form field and not as a driver exception.
 *
 * A NULL `company_id` on a non-super-admin used to be a hole rather than a
 * shape: TenantScope skipped filtering entirely and the account saw every
 * tenant. That is fixed in the same change — see TenantScope, which now
 * returns nothing instead of everything.
 *
 * ── THE BACKFILL ──
 *
 * Every `companies` row flagged `is_supplier` becomes a `suppliers` row, and
 * the four links above are rewritten through that map. It is written to be a
 * no-op on a database where nobody ever flagged one — which is expected here,
 * since the screen that could set the flag shipped a day before this change
 * replaced it — but it is written properly anyway, because "expected to be
 * empty" and "empty" are not the same sentence and the difference is
 * somebody's money.
 *
 * ── WHAT THIS MIGRATION DOES NOT DO ──
 *
 * It does not drop `companies.is_supplier`, the `companies.supplier_*`
 * columns, or `products.supplier_company_id`. Those go in a SEPARATE
 * migration, deliberately not in this deploy: after this one lands, the
 * balances on the payout screen can be compared against the old columns
 * satang for satang, and if anything disagrees the old data is still sitting
 * there to compare against. Dropping in the same breath as rewriting removes
 * the only witness.
 *
 * The two money tables are the exception — their legacy column IS dropped
 * here, because it is NOT NULL with a foreign key to `companies` and no new
 * settlement row could be written while it stood.
 *
 * ── THE ROLLBACK IS TESTED, NOT ASSUMED ──
 *
 * down() undoes all four links and puts the money tables' old column back
 * nullable, so `migrate:rollback` runs to completion and the migration before
 * this one can drop `suppliers`. An earlier draft left two foreign keys
 * pointing at that table and the rollback stopped halfway, with the schema in
 * a state neither version of the code understands. See down().
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. NEW COLUMNS, ALL NULLABLE FOR NOW ────────────────────────

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('company_id')
                ->constrained('suppliers')
                ->nullOnDelete();

            $table->index(['supplier_id', 'role']);
        });

        Schema::table('products', function (Blueprint $table) {
            // nullOnDelete, not cascade: removing a supplier must not delete
            // the products our companies are currently selling, nor the sales
            // history hanging off them. It orphans the attribution, which is
            // visible and fixable; a cascade would take the catalogue with it.
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('supplier_company_id')
                ->constrained('suppliers')
                ->nullOnDelete();

            // Every supplier-facing query filters on this and nothing else.
            $table->index('supplier_id');
        });

        Schema::table('supplier_settlement_ledger', function (Blueprint $table) {
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('id')
                ->constrained('suppliers')
                ->cascadeOnDelete();
        });

        Schema::table('supplier_withdrawal_requests', function (Blueprint $table) {
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('id')
                ->constrained('suppliers')
                ->restrictOnDelete();
        });

        // ── 2. BACKFILL ─────────────────────────────────────────────────

        $map = $this->migrateSupplierCompanies();

        foreach ($map as $companyId => $supplierId) {
            DB::table('products')
                ->where('supplier_company_id', $companyId)
                ->update(['supplier_id' => $supplierId]);

            DB::table('supplier_settlement_ledger')
                ->where('supplier_company_id', $companyId)
                ->update(['supplier_id' => $supplierId]);

            DB::table('supplier_withdrawal_requests')
                ->where('supplier_company_id', $companyId)
                ->update(['supplier_id' => $supplierId]);

            /*
             * Partner logins move across and LOSE their company_id.
             *
             * Keeping it would leave an account whose two identity columns
             * disagree about what it is, and TenantScope would quietly narrow
             * its queries to a tenant it has nothing to do with. The link is
             * not needed for anything afterwards: every supplier-facing query
             * reads supplier_id.
             */
            DB::table('users')
                ->where('role', 'company_partner')
                ->where('company_id', $companyId)
                ->update(['supplier_id' => $supplierId, 'company_id' => null]);
        }

        // ── 3. TIGHTEN, AND DROP THE LEGACY MONEY LINKS ─────────────────

        /*
         * A settlement row with no supplier cannot be paid and cannot be
         * reconciled; the same is true of a payout request. Both columns go
         * NOT NULL now that they are filled, which is what the old
         * `supplier_company_id` guaranteed and what the services rely on.
         *
         * Dropped rather than kept: unlike the columns on `companies` and
         * `products`, this one is NOT NULL with a foreign key, so leaving it
         * in place would make the next settlement row unwritable.
         */
        /*
         * ── THE ORDER HERE IS MYSQL'S, AND IT IS NOT OBVIOUS ──
         *
         * FOREIGN KEY first, THEN the index, THEN the column. Written the
         * other way round (index first, which reads more naturally) MySQL
         * refuses with:
         *
         *   1553 Cannot drop index 'ssl_supplier_status_released_idx':
         *        needed in a foreign key constraint
         *
         * …because `supplier_company_id` LEADS that composite index, so InnoDB
         * adopted it as the foreign key's backing index instead of creating
         * one of its own. The key holds the index hostage until the key goes.
         *
         * SQLite does not care — it rebuilds the whole table for any of these
         * — which is exactly why this was worth running against MySQL before
         * shipping. The test suite runs on SQLite and passed on the wrong
         * order.
         *
         * Each step is its own Schema::table call: Laravel compiles one ALTER
         * per closure, and MySQL evaluates the constraints of an ALTER as a
         * unit, so bundling them re-creates the same deadlock inside a single
         * statement.
         */
        Schema::table('supplier_settlement_ledger', function (Blueprint $table) {
            $table->dropForeign(['supplier_company_id']);
        });

        Schema::table('supplier_settlement_ledger', function (Blueprint $table) {
            $table->dropIndex('ssl_supplier_status_released_idx');
        });

        Schema::table('supplier_settlement_ledger', function (Blueprint $table) {
            $table->dropColumn('supplier_company_id');
        });

        Schema::table('supplier_withdrawal_requests', function (Blueprint $table) {
            $table->dropForeign(['supplier_company_id']);
        });

        Schema::table('supplier_withdrawal_requests', function (Blueprint $table) {
            $table->dropIndex('swr_supplier_status_idx');
        });

        Schema::table('supplier_withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn('supplier_company_id');
        });

        $this->requireSupplierOn('supplier_settlement_ledger');
        $this->requireSupplierOn('supplier_withdrawal_requests');

        // The hot query behind the payout screen, re-cut for the new column.
        Schema::table('supplier_settlement_ledger', function (Blueprint $table) {
            $table->index(['supplier_id', 'payment_status', 'released_at'], 'ssl_supplier_status_released_idx');
        });

        Schema::table('supplier_withdrawal_requests', function (Blueprint $table) {
            $table->index(['supplier_id', 'status'], 'swr_supplier_status_idx');
        });
    }

    public function down(): void
    {
        /*
         * ── THIS HAS TO ACTUALLY WORK ──
         *
         * The first draft dropped `supplier_id` from `users` and `products`
         * only, and said in a comment that the money tables were "deliberately
         * one-way". That was wrong, and testing the rollback against MySQL is
         * what showed it: two foreign keys were left pointing at `suppliers`,
         * so the migration BEFORE this one could not drop that table, and
         * `migrate:rollback` stopped half-finished with the schema in a state
         * neither version of the code understands.
         *
         * `migrate:rollback` is what somebody reaches for when a deploy has
         * gone wrong. It has to finish.
         *
         * So every `supplier_id` comes off, all four tables, and the money
         * tables get their `supplier_company_id` back — NULLABLE, where it used
         * to be NOT NULL. That is the honest shape: the values are gone (they
         * were rewritten through a map that only existed during up()), and a
         * column of nulls says so, where a NOT NULL column would simply refuse
         * to be created. Both tables were created the day before this change,
         * so in practice they are empty and the distinction is academic — but
         * "in practice empty" is not a thing to build a rollback on.
         */
        foreach ([
            ['users', ['supplier_id', 'role']],
            ['products', ['supplier_id']],
            ['supplier_settlement_ledger', 'ssl_supplier_status_released_idx'],
            ['supplier_withdrawal_requests', 'swr_supplier_status_idx'],
        ] as [$table, $index]) {
            // Foreign key, then index, then column — the MySQL ordering the
            // up() path documents at length, and it bites identically here:
            // InnoDB adopts the index that LEADS with the key's column as that
            // key's backing index and refuses to drop it while the key stands.
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['supplier_id']);
            });

            Schema::table($table, function (Blueprint $blueprint) use ($index) {
                $blueprint->dropIndex($index);
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('supplier_id');
            });
        }

        Schema::table('supplier_settlement_ledger', function (Blueprint $table) {
            $table->foreignId('supplier_company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->index(['supplier_company_id', 'payment_status', 'released_at'], 'ssl_supplier_status_released_idx');
        });

        Schema::table('supplier_withdrawal_requests', function (Blueprint $table) {
            $table->foreignId('supplier_company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->index(['supplier_company_id', 'status'], 'swr_supplier_status_idx');
        });
    }

    /**
     * Copy every flagged company into `suppliers`.
     *
     * @return array<int, int> old companies.id => new suppliers.id
     */
    private function migrateSupplierCompanies(): array
    {
        if (! Schema::hasColumn('companies', 'is_supplier')) {
            return [];
        }

        $map = [];
        $now = now();

        $columns = [
            'supplier_gp_mode' => 'gp_mode',
            'supplier_gp_value' => 'gp_value',
            'supplier_release_trigger' => 'release_trigger',
            'supplier_min_withdrawal_satang' => 'min_withdrawal_satang',
            'supplier_wht_rate' => 'wht_rate',
            'supplier_payout_bank_name' => 'payout_bank_name',
            'supplier_payout_bank_account_number' => 'payout_bank_account_number',
            'supplier_payout_bank_account_name' => 'payout_bank_account_name',
        ];

        // withoutGlobalScopes has no meaning on the query builder; migrations
        // run outside any authenticated context, so no scope applies anyway.
        foreach (DB::table('companies')->where('is_supplier', true)->get() as $company) {
            $row = [
                'name' => $company->name,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach ($columns as $old => $new) {
                // Each guarded individually: this migration has to survive
                // running against a database where only some of the earlier
                // supplier migrations landed.
                if (Schema::hasColumn('companies', $old)) {
                    $row[$new] = $company->{$old} ?? null;
                }
            }

            $map[(int) $company->id] = (int) DB::table('suppliers')->insertGetId($row);
        }

        return $map;
    }

    /** Make `supplier_id` mandatory once it is filled. */
    private function requireSupplierOn(string $table): void
    {
        /*
         * `change()` on a foreign key column needs the key dropped and put
         * back on MySQL. SQLite rebuilds the table wholesale and does not
         * care. Rather than branch on the driver, the column is re-declared
         * with its constraint intact — Laravel's schema builder handles the
         * rebuild on SQLite and emits a plain MODIFY on MySQL, and the
         * foreign key survives both because it is named in the declaration.
         */
        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unsignedBigInteger('supplier_id')->nullable(false)->change();
        });
    }
};
