<?php

namespace Tests\Feature\Platform;

use App\Enums\PipelineStage;
use App\Models\Client;
use App\Models\Company;
use App\Models\PipelineStageLog;
use App\Models\Referral;
use App\Models\User;
use App\Services\Platform\PlatformReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-180 §3 (B2) / §6 — the cross-company report's
 * `referrals_completed_payment`.
 *
 * The Service carried its own copy of [complete_payment,
 * ongoing_next_meeting], with a comment admitting it duplicated
 * ProductGradingService's. Since ADR-026, a deal advanced into จัดส่ง /
 * นัดใช้บริการ / ติดตามผล has very much completed payment, and was being
 * dropped from the one report a Super Admin reads as platform volume.
 */
class PlatformReportTest extends TestCase
{
    use RefreshDatabase;

    private function closedReferral(Company $company, User $agent, PipelineStage $stage): Referral
    {
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'referring_agent_id' => $agent->id,
        ]);

        $referral = Referral::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'current_stage' => $stage,
        ]);

        PipelineStageLog::factory()->create([
            'company_id' => $company->id,
            'referral_id' => $referral->id,
            'from_stage' => PipelineStage::Finish1stDoctorMeeting,
            'to_stage' => PipelineStage::CompletePayment,
            'changed_by_user_id' => $agent->id,
            'changed_at' => now(),
        ]);

        return $referral;
    }

    /** @return array<string, mixed> */
    private function rowFor(Company $company): array
    {
        $row = app(PlatformReportService::class)->buildReport()->firstWhere('company_id', $company->id);
        $this->assertNotNull($row, "Company {$company->id} missing from the platform report");

        return $row;
    }

    public function test_referrals_completed_payment_includes_every_post_payment_stage(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        foreach ([
            PipelineStage::CompletePayment,
            PipelineStage::OngoingNextMeeting,
            PipelineStage::Delivery,
            PipelineStage::ServiceAppointment,
            PipelineStage::FollowUp,
        ] as $stage) {
            $this->closedReferral($company, $agent, $stage);
        }

        // Two deals that have NOT reached payment — they must stay out.
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        Referral::factory()->count(2)->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'current_stage' => PipelineStage::WaitingAppointment,
        ]);

        $row = $this->rowFor($company);

        $this->assertSame(7, $row['total_referrals']);
        $this->assertSame(5, $row['referrals_completed_payment']);
    }

    /**
     * BR-6 / §5. NOT empty-vs-empty: both companies have real closed
     * deals, in different quantities. Each row must report its OWN
     * company. Removing the `company_id` filter makes every row report the
     * platform total (3 and 3), which is what this catches.
     */
    public function test_each_company_row_counts_only_its_own_closed_deals(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $companyB->id]);

        $this->closedReferral($companyA, $agentA, PipelineStage::Delivery);

        $this->closedReferral($companyB, $agentB, PipelineStage::FollowUp);
        $this->closedReferral($companyB, $agentB, PipelineStage::CompletePayment);

        $this->assertSame(1, $this->rowFor($companyA)['referrals_completed_payment']);
        $this->assertSame(2, $this->rowFor($companyB)['referrals_completed_payment']);
        $this->assertSame(1, $this->rowFor($companyA)['total_referrals']);
        $this->assertSame(2, $this->rowFor($companyB)['total_referrals']);
    }

    /** §5 rule 5 — the endpoint stays Super-Admin-only. */
    public function test_a_company_admin_cannot_read_the_cross_company_report(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->getJson('/api/v1/platform-report')->assertForbidden();
    }
}
