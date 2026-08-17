<?php

namespace Tests\Feature\Gamification;

use App\Models\Badge;
use App\Models\Company;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// BR-5 — manual badge-awarding. UserBadge IS TenantScope'd, unlike
// Badge (platform-default definitions), so cross-company access here
// is 404, matching XpLedgerTest/CommissionLedgerTest, not
// GamificationRuleTest.
class UserBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_only_sees_their_own_earned_badges(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        UserBadge::factory()->create(['company_id' => $company->id, 'user_id' => $agentA->id]);
        UserBadge::factory()->create(['company_id' => $company->id, 'user_id' => $agentB->id]);

        $this->actingAs($agentA)->getJson('/api/v1/user-badges')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_agent_cannot_award_a_badge_to_themselves(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $badge = Badge::factory()->create(['company_id' => null]);

        $this->actingAs($agent)
            ->postJson('/api/v1/user-badges', ['user_id' => $agent->id, 'badge_id' => $badge->id])
            ->assertForbidden();
    }

    public function test_company_admin_can_award_a_badge_to_an_agent(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $badge = Badge::factory()->create(['company_id' => null]);

        $this->actingAs($admin)
            ->postJson('/api/v1/user-badges', ['user_id' => $agent->id, 'badge_id' => $badge->id])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $agent->id)
            ->assertJsonPath('data.badge.id', $badge->id);

        $this->assertDatabaseHas('user_badges', ['user_id' => $agent->id, 'badge_id' => $badge->id]);
    }

    public function test_awarding_the_same_badge_twice_is_idempotent(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $badge = Badge::factory()->create(['company_id' => null]);

        $this->actingAs($admin)->postJson('/api/v1/user-badges', ['user_id' => $agent->id, 'badge_id' => $badge->id])->assertCreated();
        // Second call resolves to the same, already-existing row
        // (firstOrCreate, wasRecentlyCreated=false), so Laravel's
        // ResourceResponse correctly returns 200, not 201 — this IS the
        // idempotency guarantee showing up at the HTTP layer, not a bug.
        $this->actingAs($admin)->postJson('/api/v1/user-badges', ['user_id' => $agent->id, 'badge_id' => $badge->id])->assertOk();

        $this->assertSame(1, UserBadge::where('user_id', $agent->id)->where('badge_id', $badge->id)->count());
    }

    public function test_company_admin_cannot_award_a_badge_to_an_agent_in_another_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $foreignAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);
        $badge = Badge::factory()->create(['company_id' => null]);

        $this->actingAs($admin)
            ->postJson('/api/v1/user-badges', ['user_id' => $foreignAgent->id, 'badge_id' => $badge->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }
}
