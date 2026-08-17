<?php

namespace Tests\Feature\Registration;

use App\Enums\AgentApprovalStatus;
use App\Events\AgentReadyForApproval;
use App\Models\Company;
use App\Models\User;
use App\Notifications\NewAgentRegistrationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// TASK-020 (ADR-005 decision 3) — the Pending Agent Approvals queue.
// Mirrors UserManagementTest's actingAs()/factory-state conventions
// (Phase 7) and FollowUpReminderNotification's Notification::fake()
// test style (TASK-016) for the notification-side assertions.
class AgentApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_access_the_approval_queue(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/agent-approvals')->assertForbidden();
    }

    public function test_company_admin_sees_only_their_own_companys_pending_registrants(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $ownPending = User::factory()->pendingApproval()->create(['company_id' => $company->id, 'email' => 'own@thailife.test']);
        User::factory()->pendingApproval()->create(['company_id' => $otherCompany->id, 'email' => 'foreign@thailife.test']);
        // An already-approved agent in the same company must not show up either.
        User::factory()->agent()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->getJson('/api/v1/agent-approvals')->assertOk();

        $emails = collect($response->json('data'))->pluck('email');
        $this->assertTrue($emails->contains($ownPending->email));
        $this->assertFalse($emails->contains('foreign@thailife.test'));
    }

    public function test_company_admin_can_approve_a_pending_registrant(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $pending = User::factory()->pendingApproval()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/agent-approvals/{$pending->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.agent_approval_status', 'approved');

        $this->assertSame(AgentApprovalStatus::Approved, $pending->fresh()->agent_approval_status);
    }

    public function test_company_admin_can_reject_a_pending_registrant_with_a_reason(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $pending = User::factory()->pendingApproval()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/agent-approvals/{$pending->id}/reject", ['reason' => 'เอกสารไม่ครบ'])
            ->assertOk()
            ->assertJsonPath('data.agent_approval_status', 'rejected')
            ->assertJsonPath('data.approval_rejection_reason', 'เอกสารไม่ครบ');

        $fresh = $pending->fresh();
        $this->assertSame(AgentApprovalStatus::Rejected, $fresh->agent_approval_status);
        $this->assertSame('เอกสารไม่ครบ', $fresh->approval_rejection_reason);
    }

    public function test_company_admin_cannot_approve_another_companys_pending_registrant(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $foreignPending = User::factory()->pendingApproval()->create(['company_id' => $otherCompany->id]);

        // TenantScope auto-scopes the {user} route-model binding itself
        // (same behavior already exercised in UserManagementTest for the
        // sibling "Manage Agents" screen) — a cross-company target 404s
        // before the Policy even runs.
        $this->actingAs($admin)
            ->putJson("/api/v1/agent-approvals/{$foreignPending->id}/approve")
            ->assertNotFound();

        $this->assertSame(AgentApprovalStatus::Pending, $foreignPending->fresh()->agent_approval_status);
    }

    public function test_approving_an_already_decided_registrant_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $alreadyApproved = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/agent-approvals/{$alreadyApproved->id}/approve")
            ->assertUnprocessable();
    }

    public function test_super_admin_can_approve_across_companies(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $pending = User::factory()->pendingApproval()->create(['company_id' => $company->id]);

        $this->actingAs($superAdmin)
            ->putJson("/api/v1/agent-approvals/{$pending->id}/approve")
            ->assertOk();
    }

    public function test_agent_ready_for_approval_notifies_every_company_admin_of_that_company_only(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin1 = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $admin2 = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $foreignAdmin = User::factory()->companyAdmin()->create(['company_id' => $otherCompany->id]);
        $registrant = User::factory()->pendingApproval()->create(['company_id' => $company->id]);

        event(new AgentReadyForApproval($registrant));

        Notification::assertSentTo([$admin1, $admin2], NewAgentRegistrationNotification::class);
        Notification::assertNotSentTo($foreignAdmin, NewAgentRegistrationNotification::class);
    }

    public function test_agent_ready_for_approval_with_no_company_admin_sends_nothing_and_does_not_error(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $registrant = User::factory()->pendingApproval()->create(['company_id' => $company->id]);

        event(new AgentReadyForApproval($registrant));

        Notification::assertNothingSent();
    }
}
