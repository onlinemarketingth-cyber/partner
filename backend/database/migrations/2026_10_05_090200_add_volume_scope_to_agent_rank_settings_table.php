<?php

use App\Enums\AgentRankVolumeScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rank qualification can count the TEAM's volume, not only the agent's own.
 *
 * ═══ WHAT WAS WRONG ═══
 *
 * StairstepCommissionService::trailingVolumeSatang() summed referrals where
 * `agent_id` was the agent themself. Nothing a downline sold counted toward
 * their upline's rank. Under a plan whose entire payout mechanism is the
 * DIFFERENCE between a manager's rank rate and their downline's, that is not
 * a missing feature — it is the plan failing to pay: a leader who recruits
 * and stops selling personally falls behind the people under them, the
 * differential goes to zero or negative, and the walk writes no ledger row.
 * is_breakaway_rank becomes unreachable for the same reason, which leaves
 * Generation — which pays per broken-away leg — with nothing to count.
 *
 * ═══ THE DEFAULT IS TODAY'S BEHAVIOUR, ON PURPOSE ═══
 *
 * 'personal' reproduces the existing query exactly. A company that never
 * changes this column recalculates the same ranks it recalculated before
 * the column existed. That equality is the whole safety argument for
 * deploying this to live companies — the same one commission_basis and
 * commission_override_rules.level were built on.
 *
 * No threshold is touched. agent_ranks.volume_threshold stays whatever the
 * company set; a company switching to 'group' will want to RAISE its
 * thresholds, and choosing those numbers is theirs (BR-7), not ours.
 *
 * ═══ WHY A COLUMN ON THE SETTINGS ROW, NOT PER RANK ═══
 *
 * agent_rank_settings already owns the two other inputs to the same
 * question — how far back volume is summed (trailing_window_days) and how
 * often it is recomputed. "Whose volume" is the third input to that one
 * calculation, and splitting it onto agent_ranks would let a ladder ask a
 * different question at each rung, which no real plan does and which would
 * make the walk's comparison between two ranks meaningless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_rank_settings', function (Blueprint $table) {
            // string, not a DB enum — same treatment as
            // recalculation_frequency on this table (the vocabulary lives
            // in App\Enums\AgentRankVolumeScope, where it can gain a case
            // without an ALTER TABLE on a live tenant).
            $table->string('volume_scope')
                ->default(AgentRankVolumeScope::Personal->value)
                ->after('trailing_window_days');
        });
    }

    public function down(): void
    {
        Schema::table('agent_rank_settings', function (Blueprint $table) {
            $table->dropColumn('volume_scope');
        });
    }
};
