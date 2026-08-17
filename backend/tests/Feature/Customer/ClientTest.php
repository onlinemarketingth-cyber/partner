<?php

namespace Tests\Feature\Customer;

use App\Models\Client;
use App\Models\Company;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// CLAUDE.md Section 5 rule 4: "Agent: sees only records where
// agent_id = self and within their own company_id." Client is the
// first domain in this codebase that actually needs this — Product
// Catalog/Academy content is shared company-wide, but Clients are
// PDPA-sensitive personal data belonging to whichever Agent referred
// them.
class ClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_only_sees_their_own_referred_clients(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agentA->id]);
        Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agentB->id]);

        $this->actingAs($agentA)
            ->getJson('/api/v1/clients')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_agent_cannot_view_a_colleagues_client(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $colleaguesClient = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agentB->id]);

        $this->actingAs($agentA)
            ->getJson("/api/v1/clients/{$colleaguesClient->id}")
            ->assertForbidden();
    }

    public function test_company_admin_sees_all_clients_in_their_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        Client::factory()->count(2)->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->getJson('/api/v1/clients')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_agent_creating_a_client_is_always_referred_to_themself(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // An Agent never submits referring_agent_id at all — the
        // Service forces it to self regardless.
        $this->actingAs($agent)
            ->postJson('/api/v1/clients', [
                'name' => 'Test Client',
                'phone' => '0800000000',
            ])
            ->assertCreated()
            ->assertJsonPath('data.referring_agent_id', $agent->id);
    }

    public function test_agent_submitting_a_referring_agent_id_is_rejected_at_validation(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $otherAgent = User::factory()->agent()->create(['company_id' => $company->id]);

        // Belt-and-braces (Section 6 "never trust client input"): an
        // Agent spoofing referring_agent_id is rejected at the
        // validation layer (422), not just silently discarded by the
        // Service downstream.
        $this->actingAs($agent)
            ->postJson('/api/v1/clients', [
                'name' => 'Test Client',
                'phone' => '0800000000',
                'referring_agent_id' => $otherAgent->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referring_agent_id');
    }

    public function test_agent_cannot_delete_a_client(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->deleteJson("/api/v1/clients/{$client->id}")
            ->assertForbidden();
    }

    public function test_health_notes_are_encrypted_at_rest(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->postJson('/api/v1/clients', [
            'name' => 'Test Client',
            'phone' => '0800000000',
            'health_notes' => 'sensitive medical info',
        ])->assertCreated();

        $rawValue = \Illuminate\Support\Facades\DB::table('clients')->value('health_notes');

        $this->assertNotSame('sensitive medical info', $rawValue);
    }

    public function test_cross_tenant_client_access_is_404(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $foreignClient = Client::factory()->create(['company_id' => $otherCompany->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/clients/{$foreignClient->id}")
            ->assertNotFound();
    }

    // Human-requested: Client Management should show each client's
    // "status" + "products of interest" — reuses the existing Referral
    // relationship rather than a new field (see ClientResource's own
    // comment). These tests confirm that data actually surfaces, and
    // that a client with several referrals shows all of them, not just
    // one collapsed value.
    public function test_client_response_includes_its_referrals_with_product_and_stage(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->getJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.referrals.0.id', $referral->id)
            ->assertJsonPath('data.referrals.0.product.id', $referral->product_id)
            ->assertJsonPath('data.referrals.0.current_stage.key', 'complete_registered');
    }

    public function test_client_with_multiple_referrals_shows_all_of_them(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        Referral::factory()->count(2)->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->getJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.referrals');
    }

    public function test_client_with_no_referrals_shows_an_empty_list_not_an_error(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->getJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.referrals');
    }

    // Human-requested (2026-07-13, CRM-standards comparison): a
    // client-level status independent of any Referral, so a client
    // with zero referrals still shows something meaningful instead of
    // nothing.
    public function test_new_client_defaults_to_new_status(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/clients', [
                'name' => 'Test Client',
                'phone' => '0800000000',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status.key', 'new');
    }

    public function test_client_status_cannot_be_set_at_creation(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // Even if a client tries to sneak a status in at creation, it's
        // silently ignored (not in StoreClientRequest's rules) — every
        // new client always starts at ClientStatus::New.
        $this->actingAs($agent)
            ->postJson('/api/v1/clients', [
                'name' => 'Test Client',
                'phone' => '0800000000',
                'status' => 'interested',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status.key', 'new');
    }

    public function test_client_status_can_be_updated(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->putJson("/api/v1/clients/{$client->id}", ['status' => 'contacted'])
            ->assertOk()
            ->assertJsonPath('data.status.key', 'contacted')
            ->assertJsonPath('data.status.label', 'Contacted');
    }

    public function test_client_status_rejects_an_invalid_value(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->putJson("/api/v1/clients/{$client->id}", ['status' => 'not_a_real_status'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_client_lead_source_is_optional_and_can_be_set(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/clients', [
                'name' => 'Test Client',
                'phone' => '0800000000',
                'lead_source' => 'Facebook Ads',
            ])
            ->assertCreated()
            ->assertJsonPath('data.lead_source', 'Facebook Ads');
    }

    // TASK-014: demographic fields — human-requested (2026-07-13),
    // following a CRM-standards comparison. All optional, general
    // personal data (Section 6).
    public function test_client_demographic_fields_are_optional_and_round_trip(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/clients', [
                'name' => 'Test Client',
                'phone' => '0800000000',
                'date_of_birth' => '1990-05-15',
                'address' => '123 หมู่ 4 ถนนสุขุมวิท',
                'province' => 'เชียงใหม่',
                'occupation' => 'พนักงานบริษัท',
            ])
            ->assertCreated()
            ->assertJsonPath('data.date_of_birth', '1990-05-15')
            ->assertJsonPath('data.address', '123 หมู่ 4 ถนนสุขุมวิท')
            ->assertJsonPath('data.province', 'เชียงใหม่')
            ->assertJsonPath('data.occupation', 'พนักงานบริษัท');
    }

    public function test_client_can_be_created_with_all_demographic_fields_blank(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/clients', [
                'name' => 'Test Client',
                'phone' => '0800000000',
            ])
            ->assertCreated()
            ->assertJsonPath('data.date_of_birth', null)
            ->assertJsonPath('data.province', null);
    }

    public function test_client_date_of_birth_in_the_future_is_rejected(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/clients', [
                'name' => 'Test Client',
                'phone' => '0800000000',
                'date_of_birth' => now()->addYear()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_of_birth');
    }

    public function test_client_province_rejects_a_value_that_is_not_a_real_thai_province(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/clients', [
                'name' => 'Test Client',
                'phone' => '0800000000',
                'province' => 'Not A Real Province',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('province');
    }
}
