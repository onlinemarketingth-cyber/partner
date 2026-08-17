<?php

namespace Tests\Feature\Referral;

use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\Company;
use App\Models\PipelineStageLog;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// CLAUDE.md §4.3 — "Sequential Transitions Only". Every advance() call
// takes no target-stage input (see PipelineService's docblock) — these
// tests confirm that's actually enforced end to end, not just that the
// enum's defaultAllowedNextStages() is correct in isolation.
//
// These referrals are created with NO pipeline_template_id, so since
// ADR-026/TASK-133 they exercise the LEGACY fallback path (§3.6): a
// pre-ADR-026 referral must keep walking CLAUDE.md §4.3's original five
// stages. Template-driven journeys are covered by
// PipelineTemplateAdvanceTest.
class PipelineTest extends TestCase
{
    use RefreshDatabase;

    private function makeCertifiedAgentReferral(): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $referral = Referral::create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null,
            'submitted_at' => now(),
        ]);

        // This helper creates the Referral directly (bypassing
        // ReferralService::create()) to skip re-doing BR-1 cert-gate
        // setup for every test in this file — but that means it also
        // skips the initial "creation" PipelineStageLog row that
        // ReferralService::create() normally writes (Section 4.3: the
        // CompleteRegistered state counts as the first audit-trail
        // entry, from_stage null). Recreated here so
        // test_stage_logs_endpoint_returns_the_audit_trail_in_order()'s
        // "creation + 2 advances = 3 entries" expectation actually holds.
        PipelineStageLog::create([
            'company_id' => $referral->company_id,
            'referral_id' => $referral->id,
            'from_stage' => null,
            'to_stage' => $referral->current_stage,
            'changed_by_user_id' => $agent->id,
            'changed_at' => $referral->submitted_at,
        ]);

        return [$company, $agent, $referral];
    }

    public function test_advancing_moves_through_stages_sequentially(): void
    {
        [, $agent, $referral] = $this->makeCertifiedAgentReferral();

        $expected = [
            'waiting_appointment',
            'finish_1st_doctor_meeting',
            'complete_payment',
            'ongoing_next_meeting',
        ];

        foreach ($expected as $stage) {
            $this->actingAs($agent)
                ->postJson("/api/v1/referrals/{$referral->id}/advance")
                ->assertOk()
                ->assertJsonPath('data.current_stage.key', $stage);
        }

        $referral->refresh();
        $this->assertSame(PipelineStage::OngoingNextMeeting, $referral->current_stage);
        // First entry into Ongoing Next Meeting is "the 2nd meeting" —
        // the 1st already happened as its own earlier stage.
        $this->assertSame(2, $referral->meeting_number);
    }

    public function test_advancing_again_within_ongoing_next_meeting_increments_meeting_number(): void
    {
        [, $agent, $referral] = $this->makeCertifiedAgentReferral();
        $referral->update(['current_stage' => PipelineStage::OngoingNextMeeting, 'meeting_number' => 2]);

        $this->actingAs($agent)
            ->postJson("/api/v1/referrals/{$referral->id}/advance")
            ->assertOk()
            ->assertJsonPath('data.meeting_number', 3);

        $this->actingAs($agent)
            ->postJson("/api/v1/referrals/{$referral->id}/advance")
            ->assertOk()
            ->assertJsonPath('data.meeting_number', 4);
    }

    public function test_each_advance_creates_a_pipeline_stage_log_entry(): void
    {
        [, $agent, $referral] = $this->makeCertifiedAgentReferral();

        $this->actingAs($agent)->postJson("/api/v1/referrals/{$referral->id}/advance")->assertOk();

        $this->assertDatabaseHas('pipeline_stage_logs', [
            'referral_id' => $referral->id,
            'from_stage' => 'complete_registered',
            'to_stage' => 'waiting_appointment',
            'changed_by_user_id' => $agent->id,
        ]);
    }

    public function test_advance_ignores_a_client_supplied_target_stage(): void
    {
        // Proves the "never trust client input" design concretely — a
        // client can't skip from complete_registered straight to
        // complete_payment by sending a to_stage in the body, because
        // the endpoint doesn't read one at all.
        [, $agent, $referral] = $this->makeCertifiedAgentReferral();

        $this->actingAs($agent)
            ->postJson("/api/v1/referrals/{$referral->id}/advance", ['to_stage' => 'complete_payment'])
            ->assertOk()
            ->assertJsonPath('data.current_stage.key', 'waiting_appointment');
    }

    public function test_agent_cannot_advance_a_colleagues_referral(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $colleaguesReferral = Referral::factory()->create(['company_id' => $company->id, 'agent_id' => $agentB->id]);

        $this->actingAs($agentA)
            ->postJson("/api/v1/referrals/{$colleaguesReferral->id}/advance")
            ->assertForbidden();
    }

    public function test_stage_logs_endpoint_returns_the_audit_trail_in_order(): void
    {
        [, $agent, $referral] = $this->makeCertifiedAgentReferral();
        $this->actingAs($agent)->postJson("/api/v1/referrals/{$referral->id}/advance")->assertOk();
        $this->actingAs($agent)->postJson("/api/v1/referrals/{$referral->id}/advance")->assertOk();

        $this->actingAs($agent)
            ->getJson("/api/v1/referrals/{$referral->id}/stage-logs")
            ->assertOk()
            ->assertJsonCount(3, 'data') // creation + 2 advances
            ->assertJsonPath('data.0.to_stage.key', 'finish_1st_doctor_meeting'); // newest first
    }
}
