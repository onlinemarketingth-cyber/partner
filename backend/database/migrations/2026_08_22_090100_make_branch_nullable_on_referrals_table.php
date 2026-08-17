<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-134a — ag-lead + human ruling, 2026-08-08 (TASK-132 spec,
// §"Decision — referrals.branch on a self-serve order").
//
// `branch` is one of the four SWS Referral fields (CLAUDE.md §2) and has
// been a REQUIRED free-text string since 2026_07_09_100130. TASK-136's
// public checkout breaks that assumption: there is no agent to type a
// branch and no customer who could possibly know one. So the column
// becomes nullable.
//
// NULL rather than a placeholder, deliberately. A fake value like
// 'ONLINE' or '-' is indistinguishable from a real branch name once it
// is sitting in a free-text column, so the day branches become a real
// entity every self-serve row would have to be un-guessed by hand. NULL
// means "this sale did not happen at a branch", which is simply true,
// and is one WHERE clause away when that migration comes.
//
// This WIDENS the column only. No existing value is rewritten — every
// referral an agent has ever submitted keeps the branch they typed, and
// the authenticated agent path keeps `branch` REQUIRED in
// StoreReferralRequest (agents still know their branch; nothing about
// their UX changes). Only the future public checkout will omit it.
//
// Standing debt, recorded in the TASK-132 spec and NOT addressed here:
// `branch` remains free text, so branch-level reporting is unreliable
// (four spellings of one branch count as four).
//
// On the driver split: unlike
// 2026_07_13_120000_make_preferred_time_nullable_on_referrals_table,
// this migration uses the normal Schema builder rather than raw
// per-driver SQL plus a hand-written SQLite table rebuild. That
// migration's stated reason ("doctrine/dbal isn't installed") no longer
// applies — Laravel 11 removed the doctrine/dbal requirement and ships
// native column-change support, and this project is on Laravel 12
// (composer.json: laravel/framework ^12.0). Verified against the
// installed framework source rather than assumed (Guardrail 3):
//   - MySqlGrammar::compileChange() emits `alter table ... modify ...`
//   - SQLiteGrammar::getAlterCommands() includes 'change', and
//     SQLiteGrammar::compileAlter() performs the create-temp / copy /
//     drop / rename rebuild automatically, reconstructing columns,
//     foreign keys and indexes from the introspected table state.
// That is the same rebuild the 2026-07-13 migration hand-wrote — with
// the advantage that the framework derives the shadow table from the
// LIVE schema instead of from a hardcoded column list. Hand-mirroring
// `referrals` again would now mean re-listing every column added since
// (next_renewal_date, co_agent_id, split_percentage, affiliate_link_id,
// pipeline_template_id), and that list going stale is precisely how the
// 2026-07-13 migration broke twice in one day.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->string('branch')->nullable()->change();
        });
    }

    public function down(): void
    {
        // NOTE: this will fail if any self-serve referral with a NULL
        // branch already exists — which is correct. Rolling back a
        // widening is only meaningful while nothing has used the extra
        // room, and silently inventing a branch name for those rows to
        // make the rollback succeed would put exactly the placeholder
        // string into the column that the ruling above rejects.
        Schema::table('referrals', function (Blueprint $table) {
            $table->string('branch')->nullable(false)->change();
        });
    }
};
