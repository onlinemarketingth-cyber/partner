<?php

namespace Tests\Feature\Referral;

use App\Models\AffiliateLink;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-011/TASK-032 — authenticated CRUD for minting/listing/revoking an
// agent's own affiliate link. Section 5 rule 4 (Agent sees only their
// own) + BR-1 gate on minting.
class AffiliateLinkTest extends TestCase
{
    use RefreshDatabase;

    private function passBasicCert(User $agent, Company $company): void
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);
    }

    public function test_agent_can_mint_their_own_affiliate_link(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);

        $this->actingAs($agent)
            ->postJson('/api/v1/affiliate-links', [])
            ->assertCreated()
            ->assertJsonPath('data.agent_id', $agent->id);
    }

    public function test_agent_without_basic_certification_cannot_mint_a_link(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        // Deliberately no passBasicCert() — BR-1 not satisfied.

        $this->actingAs($agent)
            ->postJson('/api/v1/affiliate-links', [])
            ->assertUnprocessable();
    }

    public function test_agent_only_sees_their_own_links_in_index(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agentA->id]);
        AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agentB->id]);

        $response = $this->actingAs($agentA)->getJson('/api/v1/affiliate-links')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_agent_cannot_view_another_agents_link(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agentB->id]);

        $this->actingAs($agentA)
            ->getJson("/api/v1/affiliate-links/{$link->id}")
            ->assertForbidden();
    }

    public function test_company_admin_cannot_view_another_companys_link(): void
    {
        // BR-6 — cross-tenant guard, same shape as every other Policy in this app.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $link = AffiliateLink::factory()->create(['company_id' => $companyA->id, 'agent_id' => $agentA->id]);

        $this->actingAs($adminB)
            ->getJson("/api/v1/affiliate-links/{$link->id}")
            ->assertNotFound();
    }

    public function test_agent_can_revoke_their_own_link(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = AffiliateLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->deleteJson("/api/v1/affiliate-links/{$link->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('affiliate_links', ['id' => $link->id]);
    }
}
