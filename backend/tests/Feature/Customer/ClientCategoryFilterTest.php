<?php

namespace Tests\Feature\Customer;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-056 Sprint P2 — GET /clients?client_category_id= filter, layered
// on top of the existing agent-scoped narrowing (an Agent can only ever
// filter within clients they already see, per ClientController::index).
class ClientCategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_filter_their_clients_by_category(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $vip = ClientCategory::factory()->for($company)->create(['name' => 'VIP']);
        $regular = ClientCategory::factory()->for($company)->create(['name' => 'Regular']);
        Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id, 'client_category_id' => $vip->id]);
        Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id, 'client_category_id' => $regular->id]);

        $response = $this->actingAs($agent)
            ->getJson("/api/v1/clients?client_category_id={$vip->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($vip->id, $response->json('data.0.client_category_id'));
    }

    public function test_category_filter_never_widens_past_the_agents_own_clients(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $vip = ClientCategory::factory()->for($company)->create(['name' => 'VIP']);
        Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agentB->id, 'client_category_id' => $vip->id]);

        $response = $this->actingAs($agentA)
            ->getJson("/api/v1/clients?client_category_id={$vip->id}")
            ->assertOk();

        $this->assertCount(0, $response->json('data'));
    }
}
