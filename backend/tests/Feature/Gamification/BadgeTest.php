<?php

namespace Tests\Feature\Gamification;

use App\Models\Badge;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Read-only badge definition catalog — non-sensitive shared reference
// data, so any authenticated user (including Agent) may list it.
class BadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_list_platform_and_own_company_badges_but_not_other_companies(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        Badge::factory()->create(['company_id' => null]);
        Badge::factory()->create(['company_id' => $company->id]);
        Badge::factory()->create(['company_id' => $otherCompany->id]);

        $this->actingAs($agent)->getJson('/api/v1/badges')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_super_admin_sees_all_badges(): void
    {
        $company = Company::factory()->create();
        Badge::factory()->create(['company_id' => null]);
        Badge::factory()->create(['company_id' => $company->id]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->getJson('/api/v1/badges')->assertOk()->assertJsonCount(2, 'data');
    }
}
