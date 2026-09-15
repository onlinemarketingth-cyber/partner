<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-15 — one table for both kinds of payout.
 *
 * Owner: the admin's real process is ตัดสินใจจ่าย → ฝ่ายบัญชีโอนผ่านธนาคาร →
 * กลับมากดยืนยัน. This table already modelled exactly that (PendingReview →
 * Approved → Transferred, with a transfer reference), and the admin-initiated
 * route did not: it flipped commission_ledger.payment_status to Paid in one
 * irreversible click and emailed the agent that their money had arrived,
 * while accounting had not yet touched it.
 *
 * Rather than teach the ledger a third payment_status — which would mean
 * re-reading every `where payment_status = pending` in the codebase, including
 * the one that decides what an agent is allowed to withdraw — the admin route
 * now creates a row HERE. This column is the only difference between the two.
 *
 * ── WHY IT DEFAULTS TO agent_request AND IS NOT NULLABLE ──
 *
 * Every row that exists when this runs was created by an agent asking, so the
 * default states a fact about the existing data rather than guessing one. NOT
 * NULL because "a payout whose origin is unknown" is not a state this system
 * should be able to represent: the queue renders the source on every row, and
 * a null would have to be rendered as something.
 *
 * A plain string with an application-side enum, matching `status` on this same
 * table — a database ENUM would need a second migration every time the
 * vocabulary grows, on a table holding money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_withdrawal_requests', function (Blueprint $table) {
            $table->string('source', 32)
                ->default('agent_request')
                ->after('agent_id');

            // The queue's default view is "this company's open work, newest
            // first", and the three tabs narrow by status. Source is in the
            // index because the จ่ายเงิน screen also asks "show me only the
            // ones agents raised", which is the reviewer's own triage.
            $table->index(['company_id', 'status', 'source'], 'cwr_company_status_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('commission_withdrawal_requests', function (Blueprint $table) {
            $table->dropIndex('cwr_company_status_source_idx');
            $table->dropColumn('source');
        });
    }
};
