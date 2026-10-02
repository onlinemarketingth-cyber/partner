<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\User;
use App\Services\Platform\SchedulerHeartbeatService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-10-02 — "is cron running?" answered from evidence the scheduler
 * itself leaves, so a silently dead cron entry is seen by a person.
 */
class SchedulerHealthTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    public function test_a_scheduler_that_never_ran_is_reported_as_not_running(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/v1/platform/scheduler-health')
            ->assertOk()
            ->assertJsonPath('data.is_running', false)
            ->assertJsonPath('data.last_run_at', null)
            ->assertJsonPath('data.minutes_since_last_run', null)
            ->assertJsonPath('data.stale_after_minutes', SchedulerHeartbeatService::STALE_AFTER_MINUTES);
    }

    public function test_a_recent_heartbeat_reads_as_running(): void
    {
        app(SchedulerHeartbeatService::class)->beat();

        $this->actingAs($this->superAdmin())
            ->getJson('/api/v1/platform/scheduler-health')
            ->assertOk()
            ->assertJsonPath('data.is_running', true)
            ->assertJsonPath('data.minutes_since_last_run', 0);
    }

    public function test_a_heartbeat_older_than_the_threshold_reads_as_stopped(): void
    {
        app(SchedulerHeartbeatService::class)->beat();
        $this->travel(SchedulerHeartbeatService::STALE_AFTER_MINUTES + 1)->minutes();

        $this->actingAs($this->superAdmin())
            ->getJson('/api/v1/platform/scheduler-health')
            ->assertOk()
            ->assertJsonPath('data.is_running', false)
            ->assertJsonPath('data.minutes_since_last_run', SchedulerHeartbeatService::STALE_AFTER_MINUTES + 1);
    }

    public function test_the_schedule_beats_every_minute_and_running_it_records_the_beat(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => $e->description === 'scheduler-heartbeat');

        $this->assertNotNull($event, 'the heartbeat must be on the schedule');
        $this->assertSame('* * * * *', $event->expression);

        $event->run($this->app);

        $this->assertNotNull(app(SchedulerHeartbeatService::class)->lastBeatAt());
    }

    public function test_only_a_super_admin_may_read_it(): void
    {
        $company = Company::factory()->create();

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $company->id]))
            ->getJson('/api/v1/platform/scheduler-health')
            ->assertForbidden();

        $this->actingAs(User::factory()->agent()->create(['company_id' => $company->id]))
            ->getJson('/api/v1/platform/scheduler-health')
            ->assertForbidden();
    }

    public function test_a_guest_is_refused(): void
    {
        $this->getJson('/api/v1/platform/scheduler-health')->assertUnauthorized();
    }
}
