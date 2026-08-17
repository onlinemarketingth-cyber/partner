<?php

namespace App\Services\Gamification;

use App\Enums\GamificationSourceType;
use App\Models\GamificationRule;
use App\Models\RewardPointLedger;
use App\Models\User;
use App\Models\XpLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BR-5: "XP can come from two sources: (a) completing learning modules /
 * passing certification exams, (b) closing a sale / moving a client
 * through the pipeline. XP rates ... live in config (gamification_rules)."
 *
 * This Service only knows how to look up a rate and write an XpLedger
 * row — it never decides WHEN to award XP or WHO earns it (the four
 * calling Services — ModuleCompletionService, ExamAttemptService,
 * ReferralService, PipelineService — each decide that for their own
 * trigger event, and are each individually responsible for making sure
 * they only call this once per genuine achievement, not once per
 * button-click — see their own comments for how each guards that).
 *
 * Phase 10: this is also the single funnel through which
 * BadgeAutoAwardService gets triggered — every one of the 4 calling
 * Services already guards awardXp() to fire exactly once per genuine
 * achievement (module completion, exam pass, referral submitted,
 * pipeline stage advanced), which is exactly the right cadence to
 * re-check badge eligibility too. The check runs whether or not XP was
 * actually awarded (see below) so a badge condition based on
 * modules_completed_count/referrals_completed_count still fires even if
 * no gamification_rule happens to be configured for that event yet.
 */
class GamificationService
{
    public function __construct(private BadgeAutoAwardService $badgeAutoAwardService) {}

    /**
     * Returns null (and logs, never throws) if no active rule exists
     * for this source type — same non-blocking philosophy as
     * CommissionService::recordForReferral(): a missing XP config is a
     * data gap for a human to fix, never a reason to fail the action
     * that triggered it (completing a module, closing a sale, etc.
     * must never fail just because gamification isn't configured yet).
     */
    public function awardXp(User $user, GamificationSourceType $sourceType, int $sourceId): ?XpLedger
    {
        $xpValue = $this->resolveXpValue($user->company_id, $sourceType);

        $ledger = null;
        if ($xpValue === null || $xpValue <= 0) {
            Log::warning("GamificationService: no XP awarded to user {$user->id} for {$sourceType->value} (source_id {$sourceId}) — no active gamification_rule found or xp_value is 0. Configure one in Admin.");
        } else {
            // TASK-042 §1: Reward Points are a currency decoupled from XP
            // (BR-5) but earned automatically, mirroring every XP award
            // 1:1 — this is the single funnel both ledgers are written
            // from, so the mirror can never drift out of sync. Wrapped
            // in a transaction (awardXp() didn't already run in one) so
            // the two ledger rows are written atomically — never an XP
            // award without its matching Reward Point award, or vice
            // versa.
            $ledger = DB::transaction(function () use ($user, $sourceType, $sourceId, $xpValue) {
                $xpLedger = XpLedger::create([
                    'company_id' => $user->company_id,
                    'user_id' => $user->id,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'xp_awarded' => $xpValue,
                ]);

                RewardPointLedger::create([
                    'company_id' => $user->company_id,
                    'user_id' => $user->id,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'points_awarded' => $xpValue,
                    'xp_ledger_id' => $xpLedger->id,
                ]);

                return $xpLedger;
            });
        }

        $this->badgeAutoAwardService->checkAndAwardForUser($user);

        return $ledger;
    }

    /**
     * BR-2-style config resolution (mirrors how CommissionRule works):
     * a company-specific override row wins over the platform-wide
     * default (company_id null) when both exist for the same
     * source_type. GamificationRule is deliberately NOT TenantScope'd
     * (see its docblock), so this query has to filter manually.
     */
    private function resolveXpValue(?int $companyId, GamificationSourceType $sourceType): ?int
    {
        return GamificationRule::where('source_type', $sourceType)
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->orderByRaw('company_id IS NULL ASC') // company-specific (0/false) sorts before platform default (1/true)
            ->value('xp_value');
    }
}
