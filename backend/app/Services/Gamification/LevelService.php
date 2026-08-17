<?php

namespace App\Services\Gamification;

use App\Models\LevelThreshold;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * BR-5/BR-7 — reads level_thresholds (Admin-configured, never a
 * hardcoded formula) to translate a total-XP number into a level. This
 * Service only READS the config, same split as GamificationService vs
 * GamificationRuleService (read-side vs CRUD-side kept separate).
 *
 * "Level" is simply the highest level_number whose xp_required has been
 * reached — level 0 (with 0 xp_required baseline) if no thresholds are
 * configured yet or the user hasn't reached the first one, so a missing
 * config never throws, same non-blocking philosophy as the rest of
 * Gamification.
 */
class LevelService
{
    /**
     * Memoized per Service instance — Laravel resolves one instance of
     * this class per request via the container, and
     * LeaderboardController calls currentLevelForTotalXp() once per
     * agent row, so caching here (rather than in the caller) avoids an
     * N+1 query against the small, rarely-changing level_thresholds
     * table without leaking the cache across requests.
     *
     * @var Collection<int, LevelThreshold>|null
     */
    private ?Collection $thresholdsCache = null;

    /**
     * @return array{level_number: int, xp_required: int, total_xp: int, next_level_xp_required: int|null}
     */
    public function currentLevelForTotalXp(int $totalXp): array
    {
        $thresholds = $this->thresholdsCache ??= LevelThreshold::orderBy('xp_required')->get(['level_number', 'xp_required']);

        $current = $thresholds->filter(fn (LevelThreshold $t) => $t->xp_required <= $totalXp)->last();
        $next = $thresholds->first(fn (LevelThreshold $t) => $t->xp_required > $totalXp);

        return [
            'level_number' => $current?->level_number ?? 0,
            'xp_required' => $current?->xp_required ?? 0,
            'total_xp' => $totalXp,
            'next_level_xp_required' => $next?->xp_required,
        ];
    }

    /**
     * @return array{level_number: int, xp_required: int, total_xp: int, next_level_xp_required: int|null}
     */
    public function currentLevelForUser(User $user): array
    {
        $totalXp = (int) $user->xpLedger()->sum('xp_awarded');

        return $this->currentLevelForTotalXp($totalXp);
    }
}
