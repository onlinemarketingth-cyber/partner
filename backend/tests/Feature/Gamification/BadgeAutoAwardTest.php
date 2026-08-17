<?php

namespace Tests\Feature\Gamification;

use App\Enums\GamificationSourceType;
use App\Models\Badge;
use App\Models\Company;
use App\Models\GamificationRule;
use App\Models\User;
use App\Models\UserBadge;
use App\Services\Gamification\GamificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Phase 10 — integration test proving the hook actually fires:
// GamificationService::awardXp() (the single funnel used by
// ModuleCompletionService/ExamAttemptService/ReferralService/
// PipelineService) triggers BadgeAutoAwardService on every call.
class BadgeAutoAwardTest extends TestCase
{
    use RefreshDatabase;

    public function test_awarding_xp_auto_awards_a_badge_whose_condition_is_now_met(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        GamificationRule::create(['company_id' => null, 'source_type' => GamificationSourceType::ModuleCompleted, 'xp_value' => 100, 'is_active' => true]);
        $badge = Badge::create([
            'company_id' => null,
            'key' => 'xp_100_club',
            'name' => 'XP 100 Club',
            'description' => 'd',
            'icon' => 'star',
            'condition_config' => [['metric' => 'xp_total', 'operator' => '>=', 'value' => 100]],
        ]);

        app(GamificationService::class)->awardXp($agent, GamificationSourceType::ModuleCompleted, 1);

        $this->assertDatabaseHas('user_badges', ['user_id' => $agent->id, 'badge_id' => $badge->id]);
    }

    public function test_badge_is_not_awarded_when_condition_not_yet_met(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        GamificationRule::create(['company_id' => null, 'source_type' => GamificationSourceType::ModuleCompleted, 'xp_value' => 10, 'is_active' => true]);
        $badge = Badge::create([
            'company_id' => null,
            'key' => 'xp_100_club',
            'name' => 'XP 100 Club',
            'description' => 'd',
            'icon' => 'star',
            'condition_config' => [['metric' => 'xp_total', 'operator' => '>=', 'value' => 100]],
        ]);

        app(GamificationService::class)->awardXp($agent, GamificationSourceType::ModuleCompleted, 1);

        $this->assertDatabaseMissing('user_badges', ['user_id' => $agent->id, 'badge_id' => $badge->id]);
    }

    public function test_a_badge_with_no_condition_config_is_never_auto_awarded(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        GamificationRule::create(['company_id' => null, 'source_type' => GamificationSourceType::ModuleCompleted, 'xp_value' => 1000, 'is_active' => true]);
        $badge = Badge::create([
            'company_id' => null,
            'key' => 'manual_only',
            'name' => 'Manual Only',
            'description' => 'd',
            'icon' => 'star',
            'condition_config' => null,
        ]);

        app(GamificationService::class)->awardXp($agent, GamificationSourceType::ModuleCompleted, 1);

        $this->assertDatabaseMissing('user_badges', ['user_id' => $agent->id, 'badge_id' => $badge->id]);
    }

    public function test_repeated_xp_awards_do_not_duplicate_the_badge(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        GamificationRule::create(['company_id' => null, 'source_type' => GamificationSourceType::ModuleCompleted, 'xp_value' => 100, 'is_active' => true]);
        $badge = Badge::create([
            'company_id' => null,
            'key' => 'xp_100_club',
            'name' => 'XP 100 Club',
            'description' => 'd',
            'icon' => 'star',
            'condition_config' => [['metric' => 'xp_total', 'operator' => '>=', 'value' => 100]],
        ]);

        $service = app(GamificationService::class);
        $service->awardXp($agent, GamificationSourceType::ModuleCompleted, 1);
        $service->awardXp($agent, GamificationSourceType::ExamPassed, 2);

        $this->assertSame(1, UserBadge::where('user_id', $agent->id)->where('badge_id', $badge->id)->count());
    }

    public function test_a_badge_only_visible_to_another_company_is_never_awarded_cross_tenant(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        GamificationRule::create(['company_id' => null, 'source_type' => GamificationSourceType::ModuleCompleted, 'xp_value' => 1000, 'is_active' => true]);
        $foreignBadge = Badge::create([
            'company_id' => $otherCompany->id,
            'key' => 'other_company_badge',
            'name' => 'Other',
            'description' => 'd',
            'icon' => 'star',
            'condition_config' => [['metric' => 'xp_total', 'operator' => '>=', 'value' => 1]],
        ]);

        app(GamificationService::class)->awardXp($agent, GamificationSourceType::ModuleCompleted, 1);

        $this->assertDatabaseMissing('user_badges', ['user_id' => $agent->id, 'badge_id' => $foreignBadge->id]);
    }
}
