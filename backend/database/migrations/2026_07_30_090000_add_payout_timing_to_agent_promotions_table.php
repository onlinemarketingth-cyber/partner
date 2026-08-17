<?php

use App\Enums\PromotionPayoutTiming;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// TASK-042 §3 (Promotion bonus payout, BR-7 confirmed 2026-07-23):
// "payout_timing (immediate/monthly_batch), required field, set at
// promotion creation." Deliberately NOT ->default(...) at the schema
// level — unlike target_cert_tier_mode (2026_07_29, which HAD to
// default to 'exact' for backward compatibility because it's a new
// mode on an ALREADY-WORKING targeting rule), payout_timing is a brand
// new capability with no working equivalent before this migration
// (confirmed gap: "currently nothing pays out bonus_value at all").
// There is no old behavior to preserve, so no default is the correct
// choice here, not an oversight — StoreAgentPromotionRequest requires
// it explicitly on every future create.
//
// Any promotion rows created before this feature existed still need a
// real (non-null) value to satisfy the NOT NULL constraint added below
// — backfilled explicitly via UPDATE (not a schema DEFAULT, which would
// silently apply to every FUTURE insert too and defeat the point of
// "no default"). 'immediate' is the backfill choice: those rows were
// created back when nothing paid bonuses at all, so there is no real
// history to preserve either way; 'immediate' is simply the simpler of
// the two semantics and never double-pays anything (PromotionBonusService
// only ever fires going forward, on referrals that reach Complete
// Payment AFTER this code exists).
//
// MySQL: raw SQL MODIFY (doctrine/dbal isn't installed — same
// constraint/precedent as 2026_07_14_130000_relax_referral_constraints_
// on_commission_ledger_table). SQLite (test driver): left nullable at
// the DB level rather than rebuilding the whole table — the real
// enforcement point for "required" is StoreAgentPromotionRequest (Form
// Request), matching this codebase's own stated philosophy elsewhere
// ("app code is the real guard, DB constraint was only ever the
// backstop") and avoiding a risky full-table rebuild of a table whose
// shape was still being touched by a concurrently-authored migration
// (2026_07_29_090000, target_cert_tier_mode).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_promotions', function (Blueprint $table) {
            $table->string('payout_timing')->nullable()->after('bonus_value'); // App\Enums\PromotionPayoutTiming
        });

        DB::table('agent_promotions')
            ->whereNull('payout_timing')
            ->update(['payout_timing' => PromotionPayoutTiming::Immediate->value]);

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE agent_promotions MODIFY payout_timing VARCHAR(255) NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('agent_promotions', function (Blueprint $table) {
            $table->dropColumn('payout_timing');
        });
    }
};
