<?php

namespace Tests\Feature\Referral;

use App\Models\CertTier;
use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// CLAUDE.md §2 "SWS Referral", BR-1 (Basic cert gate on submission —
// enforced against the RESOLVED referring agent, not just the actor),
// Section 5 rule 4 (Agent sees only their own referrals, same shape as
// Client).
class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private function passBasicCert(User $agent, Company $company): void
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);

        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);
    }

    public function test_agent_without_basic_cert_cannot_submit_referral(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->postJson('/api/v1/referrals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay()->toDateTimeString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('agent_id');
    }

    public function test_agent_with_basic_cert_can_submit_referral_for_own_client(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->postJson('/api/v1/referrals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay()->toDateTimeString(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.agent.id', $agent->id)
            ->assertJsonPath('data.current_stage.key', 'complete_registered');

        $this->assertDatabaseHas('pipeline_stage_logs', [
            'to_stage' => 'complete_registered',
            'from_stage' => null,
        ]);
    }

    // Human request (2026-07-13): "เวลาที่สะดวกนัดไม่ต้อง validate" —
    // preferred_time is no longer required on submission.
    public function test_referral_can_be_submitted_without_a_preferred_time(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->postJson('/api/v1/referrals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
        ])
            ->assertCreated()
            ->assertJsonPath('data.preferred_time', null);
    }

    public function test_agent_cannot_submit_referral_for_a_colleagues_client(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agentA, $company);
        $colleaguesClient = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agentB->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($agentA)->postJson('/api/v1/referrals', [
            'client_id' => $colleaguesClient->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay()->toDateTimeString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('client_id');
    }

    public function test_agent_only_sees_own_referrals(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agentA, $company);
        $this->passBasicCert($agentB, $company);

        \App\Models\Referral::factory()->create(['company_id' => $company->id, 'agent_id' => $agentA->id]);
        \App\Models\Referral::factory()->create(['company_id' => $company->id, 'agent_id' => $agentB->id]);

        $this->actingAs($agentA)
            ->getJson('/api/v1/referrals')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_agent_cannot_view_a_colleagues_referral(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $colleaguesReferral = \App\Models\Referral::factory()->create(['company_id' => $company->id, 'agent_id' => $agentB->id]);

        $this->actingAs($agentA)
            ->getJson("/api/v1/referrals/{$colleaguesReferral->id}")
            ->assertForbidden();
    }

    public function test_cross_tenant_referral_access_is_404(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $foreignReferral = \App\Models\Referral::factory()->create(['company_id' => $otherCompany->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/referrals/{$foreignReferral->id}")
            ->assertNotFound();
    }

    public function test_company_admin_can_submit_referral_on_behalf_of_a_certified_agent(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/referrals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'agent_id' => $agent->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay()->toDateTimeString(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.agent.id', $agent->id);
    }

    public function test_company_admin_cannot_submit_referral_for_an_uncertified_agent(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]); // no cert
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/referrals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'agent_id' => $agent->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay()->toDateTimeString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('agent_id');
    }
}
