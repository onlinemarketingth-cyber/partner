<?php

namespace Tests\Feature\Customer;

use App\Models\Client;
use App\Models\ClientActivity;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-015 — Client Activity/Communication Log. Mirrors
// ClientDocumentTest's tenant-isolation/policy testing style.
class ClientActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_log_an_activity_on_their_own_client(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->postJson("/api/v1/clients/{$client->id}/activities", [
                'type' => 'call',
                'summary' => 'Discussed package options',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type.key', 'call')
            ->assertJsonPath('data.summary', 'Discussed package options')
            ->assertJsonPath('data.can_edit', true);
    }

    public function test_agent_cannot_log_an_activity_on_a_colleagues_client(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $colleaguesClient = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agentB->id]);

        $this->actingAs($agentA)
            ->postJson("/api/v1/clients/{$colleaguesClient->id}/activities", [
                'type' => 'call',
                'summary' => 'Trying to sneak in',
            ])
            ->assertForbidden();
    }

    public function test_activities_are_listed_newest_first(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $older = ClientActivity::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'logged_by_user_id' => $agent->id,
            'occurred_at' => now()->subDays(3),
        ]);
        $newer = ClientActivity::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'logged_by_user_id' => $agent->id,
            'occurred_at' => now()->subDay(),
        ]);

        $this->actingAs($agent)
            ->getJson("/api/v1/clients/{$client->id}/activities")
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_agent_can_edit_their_own_activity(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $activity = ClientActivity::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'logged_by_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->putJson("/api/v1/client-activities/{$activity->id}", ['summary' => 'Corrected summary'])
            ->assertOk()
            ->assertJsonPath('data.summary', 'Corrected summary');
    }

    public function test_agent_cannot_edit_a_colleagues_activity(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agentB->id]);
        $activity = ClientActivity::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'logged_by_user_id' => $agentB->id,
        ]);

        $this->actingAs($agentA)
            ->putJson("/api/v1/client-activities/{$activity->id}", ['summary' => 'Trying to edit'])
            ->assertForbidden();
    }

    public function test_company_admin_cannot_edit_an_agents_activity_either(): void
    {
        // Deliberately narrower than ClientPolicy::update — only the
        // original logger may correct their own entry, not even a
        // Company Admin (ClientActivityPolicy's own design note).
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $activity = ClientActivity::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'logged_by_user_id' => $agent->id,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/client-activities/{$activity->id}", ['summary' => 'Admin trying to edit'])
            ->assertForbidden();
    }

    public function test_only_admin_can_delete_an_activity(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $activity = ClientActivity::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'logged_by_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->deleteJson("/api/v1/client-activities/{$activity->id}")
            ->assertForbidden();

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->actingAs($admin)
            ->deleteJson("/api/v1/client-activities/{$activity->id}")
            ->assertNoContent();
    }

    public function test_cross_tenant_activity_access_is_404(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $foreignClient = Client::factory()->create(['company_id' => $otherCompany->id]);
        $foreignActivity = ClientActivity::factory()->create([
            'company_id' => $otherCompany->id,
            'client_id' => $foreignClient->id,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/clients/{$foreignClient->id}/activities")
            ->assertNotFound();

        $this->actingAs($admin)
            ->putJson("/api/v1/client-activities/{$foreignActivity->id}", ['summary' => 'x'])
            ->assertNotFound();
    }

    public function test_follow_up_at_can_be_left_blank(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->postJson("/api/v1/clients/{$client->id}/activities", [
                'type' => 'chat',
                'summary' => 'Quick LINE chat, no follow-up needed',
            ])
            ->assertCreated()
            ->assertJsonPath('data.follow_up_at', null);
    }
}
