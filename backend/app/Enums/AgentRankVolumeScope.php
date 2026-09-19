<?php

namespace App\Enums;

/**
 * ADR-011 §3c — WHOSE sales count toward an agent's rank.
 *
 * ═══ WHY THIS EXISTS ═══
 *
 * Rank qualification read `referrals.agent_id = $agent` and nothing else:
 * an agent's rank was decided by what they personally sold, and by nothing
 * their team sold. Under Stairstep/Breakaway — and under Generation, which
 * is built on top of Stairstep's ranks — that makes the plan unrunnable
 * rather than merely unusual:
 *
 *   · A leader whose whole job is building a team cannot out-rank the
 *     people they recruited, because recruiting adds nothing to their own
 *     number. The differential walk then pays them the difference between
 *     their rank and their downline's — which is zero or negative, so no
 *     ledger row is written at all.
 *   · is_breakaway_rank can never be reached by a leader either, so
 *     Generation (which counts breakaway legs) has nothing to count.
 *
 * Every published Stairstep plan qualifies rank on GROUP volume (personal
 * plus the downline's), with personal volume as a separate, smaller
 * requirement. Nothing in this codebase could express that.
 *
 * ═══ WHY IT IS A SWITCH AND NOT A FIX ═══
 *
 * Personal-only qualification is a real arrangement (it is what a flat
 * sales-rank ladder does, and it is what every existing company here is
 * running today). Which one a company promises its agents is a business
 * rule (BR-7). So this is a column whose default reproduces exactly what
 * the code did before it existed.
 */
enum AgentRankVolumeScope: string
{
    /**
     * Only what the agent personally sold (referrals.agent_id). Today's
     * behaviour, byte for byte, and the default for that reason.
     */
    case Personal = 'personal';

    /**
     * The agent's own sales PLUS every sale made by anyone below them in
     * the manager_id tree — the industry's "group volume" (GV).
     */
    case Group = 'group';

    public static function default(): self
    {
        return self::Personal;
    }
}
