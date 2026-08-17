<?php

namespace Tests\Feature\Notification;

use App\Enums\NotificationType;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-053 / ADR-016 Phase 1 — personal notifications: own-only, unread
// count, mark read, and a user cannot touch another user's notification.
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function notify(User $user, ?NotificationType $type = null): Notification
    {
        return app(NotificationService::class)->notify(
            $user,
            $type ?? NotificationType::System,
            'ทดสอบ',
            'เนื้อหา',
        );
    }

    public function test_service_creates_a_notification_scoped_to_the_recipient(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $n = $this->notify($agent, NotificationType::CommissionPaid);

        $this->assertSame($agent->id, $n->user_id);
        $this->assertSame($company->id, $n->company_id);
        $this->assertNull($n->read_at);
        $this->assertSame(NotificationType::CommissionPaid, $n->type);
    }

    public function test_agent_lists_only_their_own_notifications(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->notify($agentA);
        $this->notify($agentA);
        $this->notify($agentB);

        $this->actingAs($agentA)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($agentA)->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);
    }

    public function test_mark_read_and_read_all(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $n = $this->notify($agent);
        $this->notify($agent);

        $this->actingAs($agent)->postJson("/api/v1/notifications/{$n->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);
        $this->assertNotNull($n->refresh()->read_at);

        $this->actingAs($agent)->postJson('/api/v1/notifications/read-all')->assertNoContent();
        $this->actingAs($agent)->getJson('/api/v1/notifications/unread-count')
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_a_user_cannot_mark_another_users_notification(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->agent()->create(['company_id' => $company->id]);
        $other = User::factory()->agent()->create(['company_id' => $company->id]);
        $n = $this->notify($owner);

        $this->actingAs($other)->postJson("/api/v1/notifications/{$n->id}/read")->assertForbidden();
        $this->assertNull($n->refresh()->read_at);
    }
}
