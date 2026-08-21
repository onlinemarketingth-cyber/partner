<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SECURITY AUDIT 2026-08-21 (V15, human ruling D3) — make a refund expressible.
 *
 * ── THE GAP THIS FILLS ──
 *
 * There was no way to undo a paid sale. Order::cancel() only works while
 * the order is still unpaid, and once a commission_ledger row exists BR-4
 * forbids editing or deleting it — correctly. So a bank transfer reversed
 * three days later, or a sale confirmed in error, had exactly one remedy:
 * hand-editing production, which is the thing BR-4 exists to prevent. The
 * rule was not the problem; the absence of a legitimate alternative was.
 *
 * ── WHY A NEGATIVE AMOUNT AND NOT A FLAG ──
 *
 * Every balance in this application is `SUM(amount_satang)` — the payout
 * summary, the CSV, the sales aggregate, the leaderboard. A negative row
 * nets out in all of them the moment it is written, with no query changed
 * anywhere. The alternative — a positive amount plus an `is_reversal` flag
 * — would leave every one of those sums silently WRONG until each was
 * found and updated, and would fail open: a query nobody remembered would
 * keep paying the refunded commission and look right doing it.
 *
 * That is why `amount_satang` stops being UNSIGNED here. It is a signed
 * 64-bit column afterwards, with the same range in the positive direction.
 *
 * ── down() REFUSES RATHER THAN CORRUPTS ──
 *
 * Narrowing back to UNSIGNED while a negative row exists does not fail in
 * MySQL, it CLAMPS — every reversal silently becomes 0 and every refunded
 * commission is quietly owed again. Same shape of decision, and the same
 * reasoning, as the company_invite_codes rollback guard: refusing loudly
 * beats destroying money quietly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            // Signed. See the docblock — this is what lets a reversal net out.
            $table->bigInteger('amount_satang')->change();

            /*
             * WHICH row this one reverses.
             *
             * Nullable because every existing row and every future EARNING
             * has nothing to point at — null here means "this is a payout",
             * which is the honest reading, not a missing value.
             *
             * No cascade, no nullOnDelete: commission_ledger rows are never
             * deleted (the model refuses it since the same audit), so a
             * delete rule here would describe a situation that cannot
             * arise. The default RESTRICT is the accurate statement.
             */
            $table->foreignId('reverses_commission_ledger_id')
                ->nullable()
                ->after('source_agent_promotion_id')
                ->constrained('commission_ledger');

            // One reversal per original, enforced by the database rather
            // than by the service that writes it. A double refund is the
            // obvious way this feature goes wrong, and the obvious way is
            // the one worth making impossible instead of merely unlikely.
            $table->unique('reverses_commission_ledger_id', 'commission_ledger_reverses_unique');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('refunded_at')->nullable()->after('paid_at');
            // Free text, required by the API. A refund with no stated
            // reason is a hole in the audit trail of a money movement.
            $table->string('refund_reason', 500)->nullable()->after('refunded_at');
            $table->foreignId('refunded_by_user_id')->nullable()->after('refund_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $reversals = DB::table('commission_ledger')->where('amount_satang', '<', 0)->count();

        if ($reversals > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$reversals} reversing commission entr(ies) hold a negative amount, ".
                'which an UNSIGNED column cannot store — MySQL would clamp each one to 0 and silently make '.
                'every refunded commission payable again. Resolve those entries first, deliberately.'
            );
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_by_user_id');
            $table->dropColumn(['refunded_at', 'refund_reason']);
        });

        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropUnique('commission_ledger_reverses_unique');
            $table->dropConstrainedForeignId('reverses_commission_ledger_id');
            $table->unsignedBigInteger('amount_satang')->change();
        });
    }
};
