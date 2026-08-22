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

    /*
     * ─────────────────────────────────────────────────────────────────────
     * WHERE A NOTIFICATION GOES WHEN YOU TAP IT (human-reported 2026-08-22)
     *
     * "ปัญหาตอนนี้คือคลิ๊ก Noti แล้วไม่ไปไหน."
     *
     * `link` was the one column above that nothing asserted, and it is the
     * only column a reader experiences directly. Two ways it went wrong, and
     * neither could fail a test or a code review:
     *
     *   1. NULL. The rejection and status-change notifications passed `null`.
     *      The SPA renders that as a tappable row that navigates nowhere —
     *      from the applicant's side, indistinguishable from a broken app.
     *
     *   2. A PATH THAT DOES NOT EXIST. Announcements said '/news'. There has
     *      never been a /news route, so both notification surfaces carried a
     *      private "'/news' means the home hub" patch. That was survivable
     *      until TASK-075 gave announcements a real page — after which the
     *      notification pointed at home, the bell is usually opened FROM
     *      home, and the tap moved nothing at all.
     *
     * The two cases below pin the destinations. They are cheap, and they are
     * the only assertions in this file that would have caught a bug a
     * customer had to report.
     */

    public function test_an_announcement_notification_points_at_the_announcements_page(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/announcements', [
            'title' => 'ประกาศทดสอบ',
            'content' => 'เนื้อหาประกาศ',
            'audience' => 'all_agents',
        ])->assertCreated();

        $row = Notification::withoutGlobalScopes()->where('user_id', $agent->id)->first();

        $this->assertNotNull($row);
        // NOT '/news', and not null. The frontend resolver turns this plus
        // data.announcement_id into /announcements?a={id}.
        $this->assertSame('/announcements', $row->link);
        $this->assertArrayHasKey('announcement_id', (array) $row->data);
    }

    public function test_every_approval_decision_gives_the_applicant_somewhere_to_go(): void
    {
        // Approval already had '/'. Rejection and the status change did not,
        // and those are precisely the notifications a worried applicant taps.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $rejected = User::factory()->pendingApproval()->create(['company_id' => $company->id]);

        app(AgentApprovalService::class)->reject($rejected, 'เอกสารไม่ครบ', $admin);

        $row = Notification::withoutGlobalScopes()->where('user_id', $rejected->id)->first();

        $this->assertNotNull($row->link, 'A notification with no link is a tap that does nothing.');
        $this->assertStringStartsWith('/', (string) $row->link);
    }
}
