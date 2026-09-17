<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-17 — THE SECOND DEPLOY. Do not ship this with the first one.
 *
 * The repoint migration (2026_10_02_090100) moved every supplier link onto the
 * new `suppliers` table and left these columns behind on purpose:
 *
 *   companies.is_supplier
 *   companies.supplier_gp_mode / _gp_value / _release_trigger
 *   companies.supplier_min_withdrawal_satang / _wht_rate
 *   companies.supplier_payout_bank_name / _account_number / _account_name
 *   products.supplier_company_id
 *
 * Nothing reads them any more — they are absent from every model's $fillable
 * and casts(), so even a stray mass-assignment cannot write to them. They are
 * kept for ONE purpose: after the first deploy lands, every supplier balance
 * on the payout screen can be compared against the old data satang for satang,
 * and if anything disagrees the witness is still sitting in the table.
 *
 * Dropping in the same breath as rewriting removes that witness. So this is a
 * separate file, held back from the first deploy deliberately.
 *
 * ── WHEN TO RUN IT ──
 *
 * After the first deploy has been live long enough to be trusted, and after
 * somebody has actually looked at จัดการคู่ค้า and confirmed the suppliers and
 * their balances are the ones they should be. Then drop this file into
 * backend/database/migrations/ and deploy again.
 *
 * ── IF PRODUCTION NEVER HAD A SUPPLIER ──
 *
 * Which is the expected case: the screen that could set `is_supplier` shipped
 * one day before the rework replaced it, so the backfill almost certainly
 * copied nothing. There is then nothing to compare and this can follow
 * immediately. `php artisan tinker --execute="echo
 * \App\Models\Company::withoutGlobalScopes()->where('is_supplier', true)
 * ->count();"` on the host answers it in one line — but run it BEFORE the
 * first deploy, because afterwards the model no longer casts the column.
 *
 * ── down() ──
 *
 * Puts the columns back, empty. That is honest rather than useless: the data
 * lives in `suppliers` now, and a rollback of the CODE needs the columns to
 * exist, not to be full. Restoring the values would mean writing a supplier
 * back into a `companies` row that may never have been one.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Foreign key, THEN index, THEN column — see the repoint migration's
         * note for the full reasoning. In short: InnoDB adopted
         * `products_supplier_company_id_index` as the foreign key's backing
         * index and refuses to drop it while the key stands (error 1553), and
         * SQLite hides the problem because it rebuilds the table whole.
         *
         * One ALTER per closure, for the same reason: MySQL evaluates an
         * ALTER's constraints as a unit, so bundling the three re-creates the
         * deadlock inside a single statement.
         */
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['supplier_company_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['supplier_company_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('supplier_company_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'is_supplier',
                'supplier_gp_mode',
                'supplier_gp_value',
                'supplier_release_trigger',
                'supplier_min_withdrawal_satang',
                'supplier_wht_rate',
                'supplier_payout_bank_name',
                'supplier_payout_bank_account_number',
                'supplier_payout_bank_account_name',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('is_supplier')->default(false);
            $table->string('supplier_gp_mode', 32)->nullable();
            $table->unsignedBigInteger('supplier_gp_value')->nullable();
            $table->string('supplier_release_trigger', 32)->nullable();
            $table->unsignedBigInteger('supplier_min_withdrawal_satang')->nullable();
            $table->unsignedInteger('supplier_wht_rate')->nullable();
            $table->string('supplier_payout_bank_name')->nullable();
            $table->string('supplier_payout_bank_account_number')->nullable();
            $table->string('supplier_payout_bank_account_name')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('supplier_company_id')
                ->nullable()
                ->after('company_id')
                ->constrained('companies')
                ->nullOnDelete();

            $table->index('supplier_company_id');
        });
    }
};
