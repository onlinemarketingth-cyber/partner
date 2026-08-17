<?php

namespace Tests\Feature\Gamification;

use App\Models\Company;
use App\Models\User;
use App\Models\XpLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// BR-5 — fully read-only ledger, same "own earnings only" shape as
// CommissionLedgerTest. XpLedger IS TenantScope'd (unlike
// GamificationRule/Badge), so cross-company access is 404 here.
class XpLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_only_sees_their_own_xp_entries(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        XpLedger::factory()->create(['company_id' => $company->id, 'user_id' => $agentA->id]);
        XpLedger::factory()->create(['company_id' => $company->id, 'user_id' => $agentB->id]);

        $this->actingAs($agentA)
            ->getJson('/api/v1/xp-ledger')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $agentA->id);
    }

    public function test_company_admin_sees_all_entries_in_their_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        XpLedger::factory()->create(['company_id' => $company->id, 'user_id' => $agentA->id]);
        XpLedger::factory()->create(['company_id' => $company->id, 'user_id' => $agentB->id]);

        $this->actingAs($admin)->getJson('/api/v1/xp-ledger')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_agent_cannot_view_a_colleagues_xp_entry_directly(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $entry = XpLedger::factory()->create(['company_id' => $company->id, 'user_id' => $agentB->id]);

        $this->actingAs($agentA)->getJson("/api/v1/xp-ledger/{$entry->id}")->assertForbidden();
    }

    public function test_cross_company_xp_entry_access_is_404(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $foreignEntry = XpLedger::factory()->create(['company_id' => $otherCompany->id]);

        $this->actingAs($admin)->getJson("/api/v1/xp-ledger/{$foreignEntry->id}")->assertNotFound();
    }

    public function test_xp_ledger_has_no_write_endpoints(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/xp-ledger', ['user_id' => $admin->id, 'source_type' => 'module_completed', 'source_id' => 1, 'xp_awarded' => 100])
            ->assertStatus(405);
    }
}
