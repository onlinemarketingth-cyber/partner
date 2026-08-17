<?php

namespace Tests\Feature\Catalog;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-007 — no prior test coverage existed for this endpoint at all. Added
// alongside the BR-6 IDOR fix (VideoProcessingSettingService::upsert() was
// letting a client-supplied company_id in the payload override the
// server-resolved match key via updateOrCreate()'s fill()) — same shape as
// AgentRankSettingTest/CommissionBinarySettingTest/etc.
class VideoProcessingSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_view_or_update_video_processing_settings(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/video-processing-settings')->assertForbidden();
        $this->actingAs($agent)->putJson('/api/v1/video-processing-settings', [
            'max_upload_mb' => 500, 'target_resolution' => '720p', 'target_bitrate_kbps' => 2500,
        ])->assertForbidden();
    }

    public function test_company_admin_can_configure_video_processing_settings(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // Unlike AgentRankSettingController/etc., update() re-fetches via
        // forCompany() (which returns a plain array — a merged "override or
        // platform default" view, never absent) rather than returning the
        // model updateOrCreate() just handed back, so JsonResource's
        // wasRecentlyCreated-based auto-201 never applies here: this
        // endpoint always responds 200, update or first-time create alike.
        $this->actingAs($admin)
            ->putJson('/api/v1/video-processing-settings', [
                'max_upload_mb' => 500, 'target_resolution' => '720p', 'target_bitrate_kbps' => 2500,
            ])
            ->assertOk()
            ->assertJsonPath('data.max_upload_mb', 500);

        $this->actingAs($admin)
            ->getJson('/api/v1/video-processing-settings')
            ->assertOk()
            ->assertJsonPath('data.max_upload_mb', 500);
    }

    // BR-6/§5 regression lock — same shape as
    // AgentRankSettingTest::test_company_admin_company_id_param_is_ignored_scoped_to_own_company_only().
    public function test_company_admin_company_id_param_is_ignored_scoped_to_own_company_only(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminA)->putJson('/api/v1/video-processing-settings', [
            'max_upload_mb' => 500,
            'target_resolution' => '720p',
            'target_bitrate_kbps' => 2500,
            'company_id' => $companyB->id,
        ])->assertOk(); // see test_company_admin_can_configure_video_processing_settings() — always 200, never 201.

        $this->assertDatabaseHas('video_processing_settings', ['company_id' => $companyA->id, 'max_upload_mb' => 500]);
        $this->assertDatabaseMissing('video_processing_settings', ['company_id' => $companyB->id]);
    }
}
