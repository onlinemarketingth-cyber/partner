<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-08-27 — WHICH commission rows a withdrawal request is drawing on,
// and how much of each.
//
// ── WHY A JOIN TABLE WITH AN AMOUNT, INSTEAD OF A STATUS FLAG ON THE LEDGER ──
//
// Agents may request an arbitrary amount (human decision, 2026-08-27), and
// commission ledger rows are indivisible records of individual sales. A
// request for ฿4,000 against rows of ฿3,000 and ฿2,000 fits neither row
// exactly, so "mark the rows paid" cannot express it.
//
// Recording the ALLOCATION here solves that without touching the ledger,
// which is immutable by BR-4: a ledger row can be partly drawn on by one
// request and finished by the next, and its `payment_status` flips to paid
// only once its allocations add up to its full amount. The ledger keeps
// saying exactly what it always said — what was earned — and this table
// carries the new question, which is what has been drawn against it.
//
// It is also the audit answer to "which sales does this payout consist of",
// which a boolean on the ledger could never have given.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_withdrawal_items', function (Blueprint $table) {
            $table->id();
            /*
             * EXPLICIT, SHORT CONSTRAINT NAMES — not decoration.
             *
             * Laravel names a foreign key `{table}_{column}_foreign`, which
             * here would be
             * `commission_withdrawal_items_commission_withdrawal_request_id_foreign`
             * — 68 characters against MySQL's hard 64-character limit for an
             * identifier. The failure mode is nasty: CREATE TABLE succeeds and
             * the ALTER that adds the key dies, so the migration aborts with
             * the table already on disk and no row in `migrations`. Every
             * subsequent deploy then reports "table already exists" and never
             * mentions the real cause. (Hit on production, 2026-09-22.)
             *
             * Naming them here keeps both keys well under the limit and makes
             * the constraint findable by a human reading SHOW CREATE TABLE.
             */
            $table->unsignedBigInteger('commission_withdrawal_request_id');
            $table->foreign('commission_withdrawal_request_id', 'cwi_request_fk')
                ->references('id')
                ->on('commission_withdrawal_requests')
                ->cascadeOnDelete();

            // restrictOnDelete: a ledger row that some payout was built from
            // must not be deletable out from under the payout's own record.
            $table->unsignedBigInteger('commission_ledger_id');
            $table->foreign('commission_ledger_id', 'cwi_ledger_fk')
                ->references('id')
                ->on('commission_ledger')
                ->restrictOnDelete();

            // SIGNED, like commission_ledger.amount_satang itself since the
            // reversal migration (2026_09_20_090000). A refund is a NEGATIVE
            // ledger row, and a negative row has to be absorbed by a payout
            // the same way a positive one is drawn on — otherwise it would
            // sit unallocated forever, subtracting from the agent's
            // available balance on every future request instead of exactly
            // once. Its allocation is therefore negative too. BR-3.
            $table->bigInteger('allocated_satang');

            $table->timestamps();

            // "How much of THIS ledger row is already spoken for" — the sum
            // this index serves is the one guarding against a row being
            // drawn on twice.
            $table->index('commission_ledger_id', 'cwi_ledger_idx');
            // A request can only draw on a given row once; the allocation is
            // one number, not a series of nibbles.
            $table->unique(
                ['commission_withdrawal_request_id', 'commission_ledger_id'],
                'cwi_request_ledger_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_withdrawal_items');
    }
};
