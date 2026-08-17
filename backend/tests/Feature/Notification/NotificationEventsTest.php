<?php

namespace Tests\Feature\Notification;

use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Services\Registration\AgentApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-053 Phase 2b — the domain events that generate in-app
// notifications (announcement created, commission paid, approval
// decided). Each asserts the RECIPIENT agent gets a correctly-typed,
// company-scoped Notification row.
class NotificationEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_all_agents_announcement_notifies_every_agent_in_the_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        // An agent in a different company must NOT be notified.
        $foreign = User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($admin)->postJson('/api/v1/announcements', [
            'title' => 'ประกาศทดสอบ',
            'content' => 'เนื้อหาประกาศ',
            'audience' => 'all_agents',
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $agentA->id, 'type' => NotificationType::Announcement->value,
        ]);
        $this->assertDatabaseHas('notifications', ['user_id' => $agentB->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $foreign->id]);

        $row = Notification::where('user_id', $agentA->id)->first();
        $this->assertSame($company->id, $row->company_id);
        $this->assertSame('ประกาศทดสอบ', $row->title);
    }

    public function test_marking_a_commission_paid_notifies_the_earning_agent(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $ledger = CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'payment_status' => PaymentStatus::Pending,
            'amount_satang' => 250000,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/commission-ledger/{$ledger->id}/mark-paid")
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $agent->id,
            'type' => NotificationType::CommissionPaid->value,
        ]);
    }

    public function test_approving_a_pending_agent_notifies_them(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $pending = User::factory()->pendingApproval()->create(['company_id' => $company->id]);

        app(AgentApprovalService::class)->approve($pending, $admin);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $pending->id,
            'type' => NotificationType::ApprovalStatus->value,
        ]);
    }

    public function test_rejecting_a_pending_agent_notifies_them_with_reason(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $pending = User::factory()->pendingApproval()->create(['company_id' => $company->id]);

        app(AgentApprovalService::class)->reject($pending, 'เอกสารไม่ครบ', $admin);

        $row = Notification::withoutGlobalScopes()
            ->where('user_id', $pending->id)
            ->where('type', NotificationType::ApprovalStatus->value)
            ->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('เอกสารไม่ครบ', (string) $row->body);
    }
}
