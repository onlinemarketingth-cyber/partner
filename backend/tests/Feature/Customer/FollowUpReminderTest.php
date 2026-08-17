<?php

namespace Tests\Feature\Customer;

use App\Console\Commands\DispatchDueFollowUpReminders;
use App\Models\Client;
use App\Models\ClientActivity;
use App\Models\Company;
use App\Models\User;
use App\Notifications\FollowUpReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// TASK-016 (ADR-004). Notification::fake() intercepts the actual send —
// these tests assert WHO gets notified and HOW MANY times, not mail
// transport (that's Laravel's own tested territory).
class FollowUpReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_due_unnotified_followup_sends_exactly_one_notification_to_the_logging_agent(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $activity = ClientActivity::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'logged_by_user_id' => $agent->id,
            'follow_up_at' => now()->subMinute(),
            'follow_up_notified_at' => null,
        ]);

        $this->artisan(DispatchDueFollowUpReminders::class)->assertSuccessful();

        Notification::assertSentTo($agent, FollowUpReminderNotification::class);
        Notification::assertCount(1);

        $this->assertNotNull($activity->fresh()->follow_up_notified_at);
    }

    public function test_a_not_yet_due_followup_is_not_notified(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        ClientActivity::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'logged_by_user_id' => $agent->id,
            'follow_up_at' => now()->addHour(),
            'follow_up_notified_at' => null,
        ]);

        $this->artisan(DispatchDueFollowUpReminders::class)->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_an_already_notified_followup_is_not_resent_even_if_the_command_runs_twice(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        ClientActivity::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'logged_by_user_id' => $agent->id,
            'follow_up_at' => now()->subMinute(),
            'follow_up_notified_at' => null,
        ]);

        $this->artisan(DispatchDueFollowUpReminders::class)->assertSuccessful();
        $this->artisan(DispatchDueFollowUpReminders::class)->assertSuccessful();

        Notification::assertCount(1);
    }

    public function test_a_row_with_no_followup_at_is_never_picked_up(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        ClientActivity::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'logged_by_user_id' => $agent->id,
            'follow_up_at' => null,
            'follow_up_notified_at' => null,
        ]);

        $this->artisan(DispatchDueFollowUpReminders::class)->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_notification_goes_to_the_logger_not_the_clients_referring_agent_when_they_differ(): void
    {
        // A Company Admin can log an activity on an agent's behalf — the
        // reminder must follow whoever set the follow-up, not the
        // client's own referring_agent_id (TASK-016's explicit rule).
        Notification::fake();

        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        ClientActivity::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'logged_by_user_id' => $admin->id,
            'follow_up_at' => now()->subMinute(),
            'follow_up_notified_at' => null,
        ]);

        $this->artisan(DispatchDueFollowUpReminders::class)->assertSuccessful();

        Notification::assertSentTo($admin, FollowUpReminderNotification::class);
        Notification::assertNotSentTo($agent, FollowUpReminderNotification::class);
    }
}
