<?php

namespace Database\Seeders;

use App\Enums\GamificationSourceType;
use App\Models\Badge;
use App\Models\GamificationRule;
use App\Models\LevelThreshold;
use Illuminate\Database\Seeder;

// DEV-ONLY seed data for the Gamification domain (BR-5).
//
// gamification_rules.xp_value: PLACEHOLDER — CLAUDE.md says "XP rates
// and badge conditions live in config (gamification_rules)" (BR-5) and
// doesn't specify exact numbers anywhere, so these are not "to be
// agreed" per se (no blueprint value was ever given, unlike commission
// %), but are still arbitrary and must be treated as adjustable config,
// never assumed correct. Marked TODO: CONFIRM (BR-7) for the same
// "don't hardcode business values into logic" reasoning. Seeded with
// company_id = null (platform-wide default) so every tenant gets a
// working value out of the box; a company can override any of these
// later via POST /gamification-rules with its own company_id.
//
// badges: a small illustrative set. condition_config is deliberately
// left null on all of them by default — a basic condition-evaluation
// engine now exists (Phase 10, BadgeConditionEvaluator), but real
// earning criteria per badge are still an Admin-authored/BR-7 decision,
// not something to invent here. These still work fine with the
// manual-award flow (POST /user-badges) regardless of condition_config.
//
// level_thresholds: PLACEHOLDER curve (Phase 9) — CLAUDE.md never
// specifies an XP->Level formula or any numbers, so these are seeded
// only so the feature isn't inert out of the box; Admin can edit every
// row via GET/POST/PUT/DELETE /level-thresholds (Super Admin only, see
// LevelThresholdPolicy) or add/remove levels entirely. Marked
// TODO: CONFIRM (BR-7), same as the XP values above.
//
// Idempotent (firstOrCreate throughout), same reasoning as CatalogSeeder.
class GamificationSeeder extends Seeder
{
    public function run(): void
    {
        $defaultXpValues = [
            // TODO: CONFIRM (BR-7) — placeholder XP amounts, not sourced from any blueprint value.
            GamificationSourceType::ModuleCompleted->value => 10,
            GamificationSourceType::ExamPassed->value => 50,
            GamificationSourceType::ReferralSubmitted->value => 20,
            GamificationSourceType::PipelineStageAdvanced->value => 5,
            GamificationSourceType::PaymentComplete->value => 100,
        ];

        foreach ($defaultXpValues as $sourceType => $xpValue) {
            GamificationRule::firstOrCreate(
                ['company_id' => null, 'source_type' => $sourceType],
                ['xp_value' => $xpValue, 'is_active' => true],
            );
        }

        $badges = [
            [
                'key' => 'first_sale',
                'name' => 'First Sale',
                'description' => 'Placeholder — earned for closing your first referral (BR-7, not yet finalized).',
                'icon' => 'trophy',
            ],
            [
                'key' => 'certified_agent',
                'name' => 'Certified Agent',
                'description' => 'Placeholder — earned for passing Basic certification (BR-7, not yet finalized).',
                'icon' => 'shield_check', // must match a key in frontend Icon.vue's PATHS map
            ],
            [
                'key' => 'top_performer',
                'name' => 'Top Performer',
                'description' => 'Placeholder — earned for a strong monthly leaderboard result (BR-7, not yet finalized).',
                'icon' => 'star',
            ],
        ];

        foreach ($badges as $badge) {
            Badge::firstOrCreate(
                ['key' => $badge['key']],
                [
                    'company_id' => null,
                    'name' => $badge['name'],
                    'description' => $badge['description'],
                    'icon' => $badge['icon'],
                    'condition_config' => null,
                ],
            );
        }

        // TODO: CONFIRM (BR-7) — placeholder XP curve, not sourced from any blueprint value.
        $defaultLevelThresholds = [
            1 => 0,
            2 => 100,
            3 => 300,
            4 => 600,
            5 => 1000,
            6 => 1500,
            7 => 2200,
            8 => 3000,
            9 => 4000,
            10 => 5200,
        ];

        foreach ($defaultLevelThresholds as $levelNumber => $xpRequired) {
            LevelThreshold::firstOrCreate(
                ['level_number' => $levelNumber],
                ['xp_required' => $xpRequired],
            );
        }
    }
}
