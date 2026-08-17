<?php

namespace Tests\Feature\Authorization;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-185 §4 — THE CHARACTERIZATION SUITE. The safety net for the whole of
 * Phase 1.
 *
 * WHAT THIS IS. A record of the ACTUAL, CURRENT allow/deny outcome of every
 * endpoint TASK-186 will convert: the 17 `abort_unless(role)` sites in
 * Controllers and the 12 Form Requests whose authorize() is a raw role check.
 * Real HTTP status codes against real routes, for all four actors — agent,
 * company_admin, super_admin, and a company_admin of a DIFFERENT company.
 *
 * WHAT THIS IS NOT. It is not a statement that these outcomes are correct.
 * Where the current behaviour looks wrong it is recorded verbatim and marked
 * `TODO: CONFIRM (behaviour recorded, not endorsed)` — see MATRIX below and
 * the report for TASK-185. A defect found here becomes its own task with its
 * own human decision; "correcting" one inside a no-behaviour-change refactor
 * is the worst thing that could happen in this phase (TASK-185 §4).
 *
 * WHY THE EXACT CODE MATTERS. 403 vs 404 vs 422 vs 500 are all present below
 * and they mean different things (refused / does not exist for you / your input
 * does not resolve inside your tenant / it crashed). A conversion that turns
 * any one of them into another has changed behaviour even if the request still
 * fails. assertStatus(), never assertForbidden()-or-similar family assertions
 * that would blur them.
 *
 * WRITTEN AGAINST UNTOUCHED APPLICATION CODE. Nothing in app/Http or
 * app/Policies changed in TASK-185; this suite passed before the Ability enum
 * and PermissionResolver existed and must keep passing after every conversion.
 */
class RoleGateCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const ACTORS = ['agent', 'company_admin', 'super_admin', 'foreign_company_admin'];

    /**
     * THE RECORDED MATRIX — endpoint => [agent, company_admin, super_admin,
     * foreign_company_admin].
     *
     * "foreign_company_admin" is a Company Admin of company B; every payload
     * below names company A's ids, so that column is the cross-tenant probe.
     * Where it reads 200/201 that is NOT cross-tenant access: the endpoint
     * silently ignores the foreign company_id and answers about the caller's
     * OWN company (see the oddity note on §BR-6-MUTE below). Where a foreign
     * id actually has to resolve — user-certifications, agent-targets upsert —
     * it is a 422.
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function matrix(): array
    {
        $table = [
            // --- 17 abort_unless(role) sites in Controllers ---------------
            // PlatformReportController.php:22 — the only isSuperAdmin()-alone site.
            'report.platform.view' => [403, 403, 200, 403],
            // ComplianceReportController.php:19
            'report.compliance.view' => [403, 200, 200, 200],
            // ConfigHealthReportController.php:19
            'report.config_health.view' => [403, 200, 200, 200],
            // SalesTeamOverviewController.php:19
            'sales.team_overview.view' => [403, 200, 200, 200],
            // AgentDashboardMetricsController.php:17
            'sales.agent_dashboard_metrics.view' => [403, 200, 200, 200],
            // AgentCommissionSummaryController.php:53
            'commission.agent_summary.view' => [403, 200, 200, 200],
            // AgentCommissionSummaryController.php:123
            'commission.agent_summary.export' => [403, 200, 200, 200],
            /*
             * AgentTargetController.php:46.
             * TODO: CONFIRM (behaviour recorded, not endorsed) — the foreign
             * Company Admin gets 200, not 403/404: the role gate passes and
             * TenantScope then yields an EMPTY list for another company's
             * agent_id. Safe (no data crosses) but indistinguishable from
             * "that agent has no targets". Asserted empty in
             * test_cross_tenant_agent_target_read_returns_an_empty_list_not_a_refusal().
             */
            'agent.target.view' => [403, 200, 200, 200],
            // AgentTargetController.php:58 — foreign agent_id fails Rule::exists → 422.
            'agent.target.update' => [403, 200, 200, 422],
            // TeamVisibilitySettingController.php:22
            'settings.team_visibility.view' => [403, 200, 200, 200],
            // AcademyCompletionSettingController.php:22
            'settings.academy_completion.view' => [403, 200, 200, 200],
            // CommissionBinarySettingController.php:21 — 204 = "not configured yet".
            'settings.commission_binary.view' => [403, 204, 204, 204],
            // CommissionMatrixSettingController.php:17
            'settings.commission_matrix.view' => [403, 204, 204, 204],
            // CommissionGenerationSettingController.php:17
            'settings.commission_generation.view' => [403, 204, 204, 204],
            // AgentRankSettingController.php:18
            'settings.agent_rank.view' => [403, 204, 204, 204],
            /*
             * VideoProcessingSettingController.php:17.
             * TODO: CONFIRM (behaviour recorded, not endorsed) — a Super Admin
             * with NO ?company_id gets a 500, not a 200/204. The controller
             * resolves $companyId to null for a Super Admin who did not name a
             * company and hands it to
             * VideoProcessingSettingService::forCompany(int $companyId), which
             * is not nullable → TypeError. Its six sibling settings endpoints
             * all tolerate null. Recorded, NOT fixed (TASK-185 §4); with a
             * ?company_id it answers 200 — pinned in
             * test_super_admin_video_processing_read_succeeds_when_a_company_is_named().
             */
            'settings.video_processing.view' => [403, 200, 500, 200],
            // CompanyThemeController.php:48
            'settings.company_theme.upload_asset' => [403, 200, 200, 200],

            // --- 12 Form Requests whose authorize() is a raw role check ----
            // Theme/UpdateThemeRequest.php:18
            'settings.company_theme.update' => [403, 200, 200, 200],
            // Sales/UpdateTeamVisibilitySettingRequest.php:19
            'settings.team_visibility.update' => [403, 200, 200, 200],
            // Academy/UpdateAcademyCompletionSettingRequest.php:14
            'settings.academy_completion.update' => [403, 200, 200, 200],
            // Commission/UpdateCommissionBinarySettingRequest.php:21 — 201, row created.
            'settings.commission_binary.update' => [403, 201, 201, 201],
            // Commission/UpdateCommissionMatrixSettingRequest.php:18
            'settings.commission_matrix.update' => [403, 201, 201, 201],
            // Commission/UpdateCommissionGenerationSettingRequest.php:15
            'settings.commission_generation.update' => [403, 201, 201, 201],
            // Commission/UpdateAgentRankSettingRequest.php:17
            'settings.agent_rank.update' => [403, 201, 201, 201],
            // Commission/UpdateCommissionSplitSettingRequest.php:20
            'settings.commission_split.update' => [403, 200, 200, 200],
            // Catalog/UpdateVideoProcessingSettingRequest.php:14
            'settings.video_processing.update' => [403, 200, 200, 200],
            // Referral/UpdateAffiliateAttributionSettingRequest.php:15
            'settings.affiliate_attribution.update' => [403, 201, 201, 201],
            // Engagement/UpdateAnnouncementSettingRequest.php:15
            'settings.announcement.update' => [403, 200, 200, 200],
            /*
             * Academy/StoreUserCertificationRequest.php:20.
             * TODO: CONFIRM (behaviour recorded, not endorsed) — this Form
             * Request is the ENTIRE authorization for a write that unlocks
             * BR-1 selling rights. UserCertificationController::store() calls
             * no $this->authorize(); there is no Policy in the path at all. A
             * Company Admin can grant any cert tier to any agent of their
             * company on the strength of a bare role check. Recorded as-is.
             * The foreign admin's 422 comes from the Rule::exists scoping in
             * rules(), i.e. from VALIDATION, not from authorization.
             */
            'academy.certification.grant' => [403, 201, 201, 422],
        ];

        $cases = [];
        foreach ($table as $endpoint => $statuses) {
            foreach (self::ACTORS as $i => $actor) {
                $cases["{$endpoint} | {$actor}"] = [$endpoint, $actor, $statuses[$i]];
            }
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function test_recorded_access_outcome(string $endpoint, string $actorKey, int $expected): void
    {
        $this->fixtures();

        $this->call_($endpoint, $actorKey)->assertStatus($expected);
    }

    /**
     * BR-6 / §5 rule 5 — the foreign Company Admin's 200 on agent-target reads
     * is not a leak. Pins WHY the matrix row above is 200: the role gate lets
     * them in and TenantScope empties the result.
     */
    public function test_cross_tenant_agent_target_read_returns_an_empty_list_not_a_refusal(): void
    {
        $this->fixtures();

        $this->call_('agent.target.view', 'foreign_company_admin')
            ->assertStatus(200)
            ->assertExactJson(['data' => []]);
    }

    /**
     * §BR-6-MUTE. TODO: CONFIRM (behaviour recorded, not endorsed) — a Company
     * Admin naming ANOTHER company's company_id is not refused; the parameter
     * is ignored and they are answered about their own tenant, with nothing
     * anywhere saying the attempt happened. Safe, but mute — the exact
     * behaviour AcademyProgressSummaryRequest.php:39-48 criticises in its own
     * docblock while the older report endpoints keep doing it. Pinned here so
     * a conversion cannot quietly turn the silence into a 403 (or into a leak).
     */
    public function test_company_admin_naming_a_foreign_company_is_answered_about_their_own(): void
    {
        [$companyA, $companyB] = $this->fixtures();

        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);

        $this->actingAs($adminB)
            ->putJson('/api/v1/commission-generation-settings', [
                'company_id' => $companyA->id,
                'max_generation_depth' => 7,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('commission_generation_settings', [
            'company_id' => $companyB->id,
            'max_generation_depth' => 7,
        ]);
        $this->assertDatabaseMissing('commission_generation_settings', [
            'company_id' => $companyA->id,
        ]);
    }

    /**
     * The other half of the video-processing 500 above: naming a company makes
     * the same Super Admin request succeed. Recorded so the crash is
     * unambiguously "null company_id", not "Super Admin".
     */
    public function test_super_admin_video_processing_read_succeeds_when_a_company_is_named(): void
    {
        [$companyA] = $this->fixtures();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson("/api/v1/video-processing-settings?company_id={$companyA->id}")
            ->assertStatus(200);
    }

    /**
     * Every route in the matrix sits behind auth:sanctum. Recorded because a
     * conversion that moves a check from a Controller into middleware could
     * turn one of these into a 403 or a 500 without anyone noticing.
     */
    public function test_every_characterized_route_refuses_a_guest_with_401(): void
    {
        $this->fixtures();

        foreach (array_keys(self::matrix()) as $key) {
            [$endpoint] = explode(' | ', $key);
            $this->call_($endpoint, null)->assertStatus(401);
        }
    }

    // -----------------------------------------------------------------
    // Fixtures + the request table
    // -----------------------------------------------------------------

    private Company $companyA;

    private Company $companyB;

    private User $targetAgent;

    private CertTier $certTier;

    /** @return array{0: Company, 1: Company} */
    private function fixtures(): array
    {
        Storage::fake('public');
        Storage::fake('local');

        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
        $this->targetAgent = User::factory()->agent()->create(['company_id' => $this->companyA->id]);
        $this->certTier = CertTier::factory()->create();

        return [$this->companyA, $this->companyB];
    }

    private function actor(?string $actorKey): ?User
    {
        return match ($actorKey) {
            null => null,
            'agent' => User::factory()->agent()->create(['company_id' => $this->companyA->id]),
            'company_admin' => User::factory()->companyAdmin()->create(['company_id' => $this->companyA->id]),
            'super_admin' => User::factory()->superAdmin()->create(),
            'foreign_company_admin' => User::factory()->companyAdmin()->create(['company_id' => $this->companyB->id]),
        };
    }

    private function call_(string $endpoint, ?string $actorKey): TestResponse
    {
        $actor = $this->actor($actorKey);
        $test = $actor === null ? $this : $this->actingAs($actor);
        $cid = $this->companyA->id;

        // The theme asset upload is the one multipart request here.
        if ($endpoint === 'settings.company_theme.upload_asset') {
            return $test->post('/api/v1/company-theme/asset', [
                'company_id' => $cid,
                'slot' => 'nav',
                'file' => UploadedFile::fake()->image('logo.png', 10, 10),
            ], ['Accept' => 'application/json']);
        }

        [$method, $uri, $payload] = match ($endpoint) {
            'report.platform.view' => ['GET', '/api/v1/platform-report', []],
            'report.compliance.view' => ['GET', '/api/v1/compliance-report', []],
            'report.config_health.view' => ['GET', '/api/v1/config-health-report', []],
            'sales.team_overview.view' => ['GET', '/api/v1/sales-team-overview', []],
            'sales.agent_dashboard_metrics.view' => ['GET', '/api/v1/agent-dashboard-metrics', []],
            'commission.agent_summary.view' => ['GET', '/api/v1/agent-commission-summary', []],
            'commission.agent_summary.export' => ['GET', '/api/v1/agent-commission-summary/export', []],
            'agent.target.view' => ['GET', "/api/v1/agent-targets?agent_id={$this->targetAgent->id}", []],
            'agent.target.update' => ['POST', '/api/v1/agent-targets', [
                'company_id' => $cid,
                'agent_id' => $this->targetAgent->id,
                'period' => '2026-08',
                'metric' => 'deals',
                'target_value' => 5,
            ]],
            'settings.team_visibility.view' => ['GET', '/api/v1/team-visibility-settings', []],
            'settings.team_visibility.update' => ['PUT', '/api/v1/team-visibility-settings', [
                'company_id' => $cid, 'client_visibility_level' => 'names', 'is_enabled' => true,
            ]],
            'settings.academy_completion.view' => ['GET', '/api/v1/academy-completion-settings', []],
            'settings.academy_completion.update' => ['PUT', '/api/v1/academy-completion-settings', [
                'company_id' => $cid, 'video_watch_percent' => 80, 'pdf_read_percent' => 80,
            ]],
            'settings.commission_binary.view' => ['GET', '/api/v1/commission-binary-settings', []],
            'settings.commission_binary.update' => ['PUT', '/api/v1/commission-binary-settings', [
                'company_id' => $cid,
                'matched_rate_type' => 'percentage',
                'matched_rate_value' => 500,
                'cycle_frequency' => 'weekly',
            ]],
            'settings.commission_matrix.view' => ['GET', '/api/v1/commission-matrix-settings', []],
            'settings.commission_matrix.update' => ['PUT', '/api/v1/commission-matrix-settings', [
                'company_id' => $cid, 'width' => 3, 'depth' => 5, 'spillover_rule' => 'breadth',
            ]],
            'settings.commission_generation.view' => ['GET', '/api/v1/commission-generation-settings', []],
            'settings.commission_generation.update' => ['PUT', '/api/v1/commission-generation-settings', [
                'company_id' => $cid, 'max_generation_depth' => 3,
            ]],
            'settings.agent_rank.view' => ['GET', '/api/v1/agent-rank-settings', []],
            'settings.agent_rank.update' => ['PUT', '/api/v1/agent-rank-settings', [
                'company_id' => $cid, 'trailing_window_days' => 30, 'recalculation_frequency' => 'monthly',
            ]],
            'settings.video_processing.view' => ['GET', '/api/v1/video-processing-settings', []],
            'settings.video_processing.update' => ['PUT', '/api/v1/video-processing-settings', [
                'company_id' => $cid,
                'max_upload_mb' => 100,
                'target_resolution' => '720p',
                'target_bitrate_kbps' => 2000,
            ]],
            'settings.company_theme.update' => ['PUT', '/api/v1/company-theme', [
                'company_id' => $cid, 'primary_hex' => '#112233',
            ]],
            'settings.commission_split.update' => ['PUT', '/api/v1/commission-split-settings', [
                'company_id' => $cid, 'is_enabled' => true,
            ]],
            'settings.affiliate_attribution.update' => ['PUT', '/api/v1/affiliate-attribution-settings', [
                'company_id' => $cid, 'attribution_window_days' => 30,
            ]],
            'settings.announcement.update' => ['PUT', '/api/v1/announcement-settings', [
                'company_id' => $cid, 'repeat_count' => 4, 'display_style' => 'full_screen',
            ]],
            'academy.certification.grant' => ['POST', '/api/v1/user-certifications', [
                'user_id' => $this->targetAgent->id, 'cert_tier_id' => $this->certTier->id,
            ]],
        };

        return $test->json($method, $uri, $payload);
    }
}
