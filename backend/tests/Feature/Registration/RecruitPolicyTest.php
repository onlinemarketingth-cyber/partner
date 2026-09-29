<?php

namespace Tests\Feature\Registration;

use App\Enums\RecruitPolicy;
use App\Enums\TeamVisibilityLevel;
use App\Models\AgentInviteLink;
use App\Models\AuditLog;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\TeamVisibilitySetting;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-049 — who may "ชวนเข้าทีม" is a per-company setting.
 *
 * Owner: "ผมเห็นด้วยทั้ง 1-3". (1) open to every agent who has passed Basic
 * by default, (2) the company may narrow it back to designated team leaders,
 * (3) approving a recruit is unchanged: pending until the recruiter or an
 * admin approves.
 *
 * The four gates (mint, consume, approve, list) all ask User::canRecruit(),
 * so every test here drives one of them through HTTP rather than calling the
 * method — that is what proves they agree.
 */
class RecruitPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private CertTier $basic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->basic = CertTier::factory()->create(['key' => 'basic']);
    }

    private function agent(bool $passedBasic = true, array $over = []): User
    {
        $agent = User::factory()->agent()->create(array_merge(['company_id' => $this->company->id], $over));

        if ($passedBasic) {
            UserCertification::create([
                'company_id' => $this->company->id,
                'user_id' => $agent->id,
                'cert_tier_id' => $this->basic->id,
                'passed_at' => now(),
            ]);
        }

        return $agent;
    }

    private function policy(RecruitPolicy $policy): void
    {
        TeamVisibilitySetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $this->company->id],
            ['client_visibility_level' => TeamVisibilityLevel::CountsOnly->value, 'is_enabled' => true, 'recruit_policy' => $policy->value],
        );
    }

    private function recruitOf(User $recruiter): User
    {
        $link = AgentInviteLink::factory()->create(['company_id' => $this->company->id, 'agent_id' => $recruiter->id]);

        return User::factory()->agent()->pendingApproval()->create([
            'company_id' => $this->company->id,
            'manager_id' => $recruiter->id,
            'recruited_via_agent_link_id' => $link->id,
            'email_verified_at' => now(),
        ]);
    }

    // ── (1) The default: anyone who passed Basic ─────────────────────────

    public function test_with_no_setting_saved_an_agent_who_passed_basic_may_recruit(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.can_recruit', true);
        $this->actingAs($agent)->postJson('/api/v1/agent-invite-links', [])->assertCreated();
    }

    public function test_an_agent_who_has_not_passed_basic_may_not(): void
    {
        $agent = $this->agent(passedBasic: false);

        $this->actingAs($agent)->getJson('/api/v1/me')->assertJsonPath('data.can_recruit', false);
        $this->actingAs($agent)->postJson('/api/v1/agent-invite-links', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_team_leader');
        $this->assertSame(0, AgentInviteLink::count());
    }

    public function test_the_default_reads_as_all_certified_in_the_settings_screen(): void
    {
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);

        $this->actingAs($admin)->getJson('/api/v1/team-visibility-settings')
            ->assertOk()
            ->assertJsonPath('data.recruit_policy', RecruitPolicy::AllCertified->value);
    }

    public function test_a_recruiter_without_the_flag_approves_and_lists_their_own_recruits(): void
    {
        $recruiter = $this->agent();
        $recruit = $this->recruitOf($recruiter);

        $this->actingAs($recruiter)->getJson('/api/v1/agent-approvals/my-recruits')
            ->assertOk()
            ->assertJsonPath('data.0.id', $recruit->id);

        // (3) unchanged — still pending until someone approves, and the
        // recruiter is someone.
        $this->actingAs($recruiter)->putJson("/api/v1/agent-approvals/{$recruit->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.agent_approval_status', 'approved');
    }

    public function test_a_pending_recruit_is_in_the_queue_not_on_the_team(): void
    {
        $recruiter = $this->agent();
        $recruit = $this->recruitOf($recruiter);

        $this->actingAs($recruiter)->getJson('/api/v1/me/home')->assertOk()->assertJsonPath('data.direct_reports_count', 0);
        $this->actingAs($recruiter)->getJson('/api/v1/me/team')->assertOk()->assertJsonCount(0, 'data.nodes');

        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);
        $this->actingAs($admin)->putJson("/api/v1/agent-approvals/{$recruit->id}/approve")->assertOk();

        $this->actingAs($recruiter)->getJson('/api/v1/me/home')->assertJsonPath('data.direct_reports_count', 1);
    }

    public function test_a_recruiter_link_admits_sign_ups(): void
    {
        $recruiter = $this->agent();
        $link = AgentInviteLink::factory()->create(['company_id' => $this->company->id, 'agent_id' => $recruiter->id]);

        $this->postJson('/api/v1/register/resolve-ref-token', ['ref_token' => $link->token])->assertOk();
    }

    public function test_the_other_companys_recruits_stay_out_of_reach(): void
    {
        $recruiter = $this->agent();
        $elsewhere = Company::factory()->create();
        $theirLeader = User::factory()->agent()->teamLeader()->create(['company_id' => $elsewhere->id]);
        $link = AgentInviteLink::factory()->create(['company_id' => $elsewhere->id, 'agent_id' => $theirLeader->id]);
        $theirRecruit = User::factory()->agent()->pendingApproval()->create([
            'company_id' => $elsewhere->id,
            'manager_id' => $theirLeader->id,
            'recruited_via_agent_link_id' => $link->id,
        ]);

        $this->actingAs($recruiter)->putJson("/api/v1/agent-approvals/{$theirRecruit->id}/approve")->assertNotFound();
        $this->actingAs($recruiter)->getJson('/api/v1/agent-approvals/my-recruits')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_removed_agent_may_not_recruit_even_with_the_flag(): void
    {
        $leader = $this->agent(over: ['is_team_leader' => true]);
        $link = AgentInviteLink::factory()->create(['company_id' => $this->company->id, 'agent_id' => $leader->id]);
        $leader->delete();

        $this->postJson('/api/v1/register/resolve-ref-token', ['ref_token' => $link->token])->assertNotFound();
    }

    // ── (2) Narrowed to designated leaders ───────────────────────────────

    public function test_designated_mode_shuts_every_gate_for_an_unflagged_agent(): void
    {
        $recruiter = $this->agent();
        $recruit = $this->recruitOf($recruiter);
        $this->policy(RecruitPolicy::Designated);

        $this->actingAs($recruiter)->getJson('/api/v1/me')->assertJsonPath('data.can_recruit', false);
        $this->actingAs($recruiter)->postJson('/api/v1/agent-invite-links', [])->assertStatus(422);
        $this->actingAs($recruiter)->getJson('/api/v1/agent-approvals/my-recruits')->assertForbidden();
        $this->actingAs($recruiter)->putJson("/api/v1/agent-approvals/{$recruit->id}/approve")->assertForbidden();
        $this->postJson('/api/v1/register/resolve-ref-token', [
            'ref_token' => AgentInviteLink::where('agent_id', $recruiter->id)->value('token'),
        ])->assertNotFound();

        // An admin can still approve the recruit left in the queue.
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);
        $this->actingAs($admin)->putJson("/api/v1/agent-approvals/{$recruit->id}/approve")->assertOk();
    }

    public function test_designated_mode_still_lets_a_flagged_leader_recruit_without_basic(): void
    {
        $leader = $this->agent(passedBasic: false, over: ['is_team_leader' => true]);
        $this->policy(RecruitPolicy::Designated);

        $this->actingAs($leader)->getJson('/api/v1/me')->assertJsonPath('data.can_recruit', true);
        $this->actingAs($leader)->postJson('/api/v1/agent-invite-links', [])->assertCreated();
    }

    public function test_the_policy_is_read_per_company(): void
    {
        $this->policy(RecruitPolicy::Designated);
        $other = Company::factory()->create();
        $otherAgent = User::factory()->agent()->create(['company_id' => $other->id]);
        UserCertification::create(['company_id' => $other->id, 'user_id' => $otherAgent->id, 'cert_tier_id' => $this->basic->id, 'passed_at' => now()]);

        $this->assertFalse($this->agent()->canRecruit());
        $this->assertTrue($otherAgent->canRecruit());
    }

    // ── The setting itself ───────────────────────────────────────────────

    public function test_a_company_admin_changes_the_policy_and_it_is_audited(): void
    {
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);

        $this->actingAs($admin)->putJson('/api/v1/team-visibility-settings', [
            'client_visibility_level' => TeamVisibilityLevel::CountsOnly->value,
            'is_enabled' => true,
            'recruit_policy' => RecruitPolicy::Designated->value,
        ])->assertOk()->assertJsonPath('data.recruit_policy', RecruitPolicy::Designated->value);

        $log = AuditLog::where('action', 'team_settings.recruit_policy_changed')->sole();
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame($this->company->id, $log->company_id);
        $this->assertSame(['recruit_policy' => 'all_certified'], $log->old_values);
        $this->assertSame(['recruit_policy' => 'designated'], $log->new_values);

        // Saving the same value again writes no second row.
        $this->actingAs($admin)->putJson('/api/v1/team-visibility-settings', [
            'client_visibility_level' => TeamVisibilityLevel::CountsOnly->value,
            'is_enabled' => true,
            'recruit_policy' => RecruitPolicy::Designated->value,
        ])->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'team_settings.recruit_policy_changed')->count());
    }

    public function test_saving_other_fields_leaves_the_policy_alone(): void
    {
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);
        $this->policy(RecruitPolicy::Designated);

        $this->actingAs($admin)->putJson('/api/v1/team-visibility-settings', [
            'client_visibility_level' => TeamVisibilityLevel::Names->value,
            'is_enabled' => true,
        ])->assertOk()->assertJsonPath('data.recruit_policy', RecruitPolicy::Designated->value);
    }

    public function test_an_unknown_policy_is_rejected(): void
    {
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);

        $this->actingAs($admin)->putJson('/api/v1/team-visibility-settings', [
            'client_visibility_level' => TeamVisibilityLevel::CountsOnly->value,
            'is_enabled' => true,
            'recruit_policy' => 'everyone',
        ])->assertStatus(422)->assertJsonValidationErrors('recruit_policy');
    }

    public function test_an_agent_cannot_change_the_policy(): void
    {
        $this->actingAs($this->agent())->putJson('/api/v1/team-visibility-settings', [
            'client_visibility_level' => TeamVisibilityLevel::CountsOnly->value,
            'is_enabled' => true,
            'recruit_policy' => RecruitPolicy::Designated->value,
        ])->assertForbidden();
    }

    public function test_a_company_admin_cannot_change_another_companys_policy(): void
    {
        $other = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);

        $this->actingAs($admin)->putJson('/api/v1/team-visibility-settings', [
            'company_id' => $other->id,
            'client_visibility_level' => TeamVisibilityLevel::CountsOnly->value,
            'is_enabled' => true,
            'recruit_policy' => RecruitPolicy::Designated->value,
        ])->assertOk();

        $this->assertDatabaseMissing('team_visibility_settings', ['company_id' => $other->id]);
        $this->assertDatabaseHas('team_visibility_settings', ['company_id' => $this->company->id, 'recruit_policy' => 'designated']);
    }

    public function test_can_recruit_is_sent_only_on_the_callers_own_profile(): void
    {
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);
        $agent = $this->agent();

        $this->actingAs($admin)->getJson("/api/v1/users/{$agent->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.can_recruit');
    }
}
