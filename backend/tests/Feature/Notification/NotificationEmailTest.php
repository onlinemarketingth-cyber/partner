<?php

namespace Tests\Feature\Notification;

use App\Enums\NotificationType;
use App\Models\Company;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Notifications\AgentNotificationEmail;
use App\Services\Notification\NotificationMailer;
use App\Services\Notification\NotificationService;
use App\Support\NotificationLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Email delivery for in-app notifications (human request 2026-08-22,
 * "ส่ง email แจ้งเตือนให้กับ Agent").
 *
 * ── WHAT BREAKS SILENTLY HERE, AND WHY EACH CASE EXISTS ──
 *
 * Email is the least observable feature in a codebase. Nothing on screen
 * changes, no request fails, and the only witness is a person who did or did
 * not receive something. Every case below pins a failure that produces no
 * error anywhere:
 *
 *  1. THE MAIL SIMPLY STOPS. Someone marks AgentNotificationEmail
 *     ShouldQueue — an obvious-looking improvement — and with no queue
 *     worker running every email silently becomes a `jobs` row.
 *     NewAgentRegistrationNotification's docblock records this exact
 *     regression happening once already.
 *
 *  2. AN ADMIN REQUEST STARTS TIMING OUT. Announcements are deferred
 *     precisely because they fan out to every agent. Drop them from
 *     deferred_types and nothing fails in dev with three agents; it fails in
 *     production with three hundred, on the request that publishes company
 *     news.
 *
 *  3. SOMEONE IS MAILED THE SAME THING REPEATEDLY. The sweep runs every five
 *     minutes forever. A row that is never marked sent is 288 emails a day
 *     to one person, and a sending domain that ends up blacklisted.
 *
 *  4. AN OPT-OUT IS IGNORED. The preference exists so an agent can stop the
 *     mail. If it is only checked at write time, a deferred announcement
 *     already in flight ignores a preference switched off a minute ago.
 *
 *  5. A DEAD ADDRESS IS RETRIED FOREVER. Without the attempt cap, one
 *     bouncing agent is retried at every sweep for as long as the row lives.
 */
class NotificationEmailTest extends TestCase
{
    use RefreshDatabase;

    private function agent(array $attributes = []): User
    {
        $company = Company::factory()->create();

        return User::factory()->agent()->create(['company_id' => $company->id] + $attributes);
    }

    private function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    // ── Which events email ───────────────────────────────────────────────

    public function test_an_approval_notification_emails_the_agent_immediately(): void
    {
        Notification::fake();
        $agent = $this->agent();

        $row = $this->service()->notify(
            $agent,
            NotificationType::ApprovalStatus,
            'บัญชีของคุณได้รับการอนุมัติแล้ว',
            null,
            '/',
        );

        // Inline, not deferred: this is the mail an agent is actively
        // waiting on, and it must not depend on cron or a queue worker.
        Notification::assertSentTo($agent, AgentNotificationEmail::class);
        $this->assertNotNull($row->fresh()->emailed_at);
    }

    public function test_an_exam_result_does_not_email(): void
    {
        // The agent submitted the exam; the score is on their screen at the
        // moment this would send. Emailing it is a notification about
        // something the reader is currently looking at.
        Notification::fake();
        $agent = $this->agent();

        $row = $this->service()->notify($agent, NotificationType::ExamPassed, 'สอบผ่าน', null, '/academy');

        Notification::assertNothingSent();
        $this->assertNull($row->fresh()->email_due_at);
    }

    // ── Announcements are deferred, never sent inline ────────────────────

    public function test_an_announcement_is_queued_for_the_sweep_not_sent_inline(): void
    {
        // THE TIMEOUT GUARD. AnnouncementService loops over every targeted
        // agent inside the admin's POST; one SMTP round-trip each would time
        // that request out on any company big enough to matter.
        Notification::fake();
        $agent = $this->agent();

        $row = $this->service()->notify(
            $agent,
            NotificationType::Announcement,
            'ประกาศทดสอบ',
            null,
            '/announcements',
            ['announcement_id' => 7],
        );

        Notification::assertNothingSent();

        $fresh = $row->fresh();
        $this->assertNotNull($fresh->email_due_at, 'The row must still WANT an email.');
        $this->assertNull($fresh->emailed_at);
    }

    public function test_the_sweep_command_sends_the_deferred_announcement(): void
    {
        Notification::fake();
        $agent = $this->agent();
        $row = $this->service()->notify($agent, NotificationType::Announcement, 'ประกาศทดสอบ', null, '/announcements');

        $this->artisan('notifications:send-emails')->assertSuccessful();

        Notification::assertSentTo($agent, AgentNotificationEmail::class);
        $this->assertNotNull($row->fresh()->emailed_at);
    }

    public function test_the_sweep_never_sends_the_same_row_twice(): void
    {
        // The sweep runs every five minutes forever. A row that is never
        // marked sent is 288 emails a day to one person.
        Notification::fake();
        $agent = $this->agent();
        $this->service()->notify($agent, NotificationType::Announcement, 'ประกาศทดสอบ');

        $this->artisan('notifications:send-emails')->assertSuccessful();
        $this->artisan('notifications:send-emails')->assertSuccessful();
        $this->artisan('notifications:send-emails')->assertSuccessful();

        Notification::assertSentToTimes($agent, AgentNotificationEmail::class, 1);
    }

    // ── The agent's own preference ───────────────────────────────────────

    public function test_an_agent_who_turned_email_off_is_not_emailed(): void
    {
        Notification::fake();
        $agent = $this->agent(['email_notifications_enabled' => false]);

        $row = $this->service()->notify($agent, NotificationType::CommissionPaid, 'ค่าคอมมิชชั่นจ่ายแล้ว', null, '/commission');

        Notification::assertNothingSent();
        $this->assertNull($row->fresh()->email_due_at);
        // The IN-APP notification must still exist — the preference is about
        // email, not about being told at all.
        $this->assertDatabaseHas('notifications', ['user_id' => $agent->id]);
    }

    public function test_turning_email_off_stops_an_announcement_already_in_flight(): void
    {
        // A deferred row can sit for minutes before the sweep reaches it.
        // Checking the preference only at write time would mail somebody who
        // switched it off in that window.
        Notification::fake();
        $agent = $this->agent();
        $this->service()->notify($agent, NotificationType::Announcement, 'ประกาศทดสอบ');

        $agent->forceFill(['email_notifications_enabled' => false])->save();

        $this->artisan('notifications:send-emails')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_an_agent_can_turn_their_own_email_off_and_back_on(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)
            ->putJson('/api/v1/me/notification-preferences', ['email_notifications_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.email_notifications_enabled', false);

        $this->assertFalse($agent->fresh()->email_notifications_enabled);

        $this->actingAs($agent)
            ->putJson('/api/v1/me/notification-preferences', ['email_notifications_enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.email_notifications_enabled', true);
    }

    public function test_the_preference_endpoint_only_ever_touches_the_caller(): void
    {
        // There is no user id in the payload by design. This pins that a
        // stray one cannot be honoured — silencing somebody else's approval
        // and payment mail is not a thing any agent may do.
        $agent = $this->agent();
        $victim = User::factory()->agent()->create(['company_id' => $agent->company_id]);

        $this->actingAs($agent)
            ->putJson('/api/v1/me/notification-preferences', [
                'email_notifications_enabled' => false,
                'user_id' => $victim->id,
            ])
            ->assertOk();

        $this->assertTrue($victim->fresh()->email_notifications_enabled);
        $this->assertFalse($agent->fresh()->email_notifications_enabled);
    }

    // ── Failure handling ─────────────────────────────────────────────────

    public function test_a_failing_mail_server_does_not_break_the_business_action(): void
    {
        // THE RULE THIS FEATURE MUST NOT BREAK. The commission WAS paid. If
        // SMTP is down, the correct outcome is "no email", never "the payout
        // failed".
        Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('SMTP down'));
        $agent = $this->agent();

        $row = $this->service()->notify($agent, NotificationType::CommissionPaid, 'ค่าคอมมิชชั่นจ่ายแล้ว', null, '/commission');

        // The notification exists, the send was attempted and released back
        // for retry, and nothing was thrown at the caller.
        $fresh = $row->fresh();
        $this->assertNull($fresh->emailed_at);
        $this->assertSame(1, $fresh->email_attempts);
        $this->assertNotNull($fresh->email_due_at, 'The row must stay eligible for a retry.');
    }

    public function test_a_permanently_failing_row_stops_being_retried(): void
    {
        // Without the cap, one bouncing address is retried at every sweep
        // for as long as the row lives.
        $agent = $this->agent();
        $row = NotificationRow::withoutGlobalScopes()->create([
            'company_id' => $agent->company_id,
            'user_id' => $agent->id,
            'type' => NotificationType::Announcement,
            'title' => 'ประกาศทดสอบ',
            'email_due_at' => now(),
        ]);
        $row->forceFill(['email_attempts' => (int) config('notifications.email.max_attempts')])->save();

        Notification::fake();
        $this->artisan('notifications:send-emails')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_stale_row_is_abandoned_rather_than_sent_late(): void
    {
        // "Your account was approved" arriving two days later is worse than
        // not arriving.
        $agent = $this->agent();
        $row = NotificationRow::withoutGlobalScopes()->create([
            'company_id' => $agent->company_id,
            'user_id' => $agent->id,
            'type' => NotificationType::Announcement,
            'title' => 'ประกาศเก่า',
            'email_due_at' => now()->subHours((int) config('notifications.email.stale_hours') + 1),
        ]);

        Notification::fake();
        $this->artisan('notifications:send-emails')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($row->fresh()->emailed_at);
    }

    // ── The mail itself ──────────────────────────────────────────────────

    public function test_the_mail_is_not_queued(): void
    {
        // With QUEUE_CONNECTION=database and no guaranteed worker, a queued
        // notification inserts a jobs row and sends nothing, with no error
        // anywhere. This has already happened once in this codebase
        // (NewAgentRegistrationNotification, 2026-08-17).
        $this->assertNotInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new AgentNotificationEmail(new NotificationRow),
            'AgentNotificationEmail must send synchronously — no queue worker is guaranteed.'
        );
    }

    public function test_the_mail_links_to_the_same_place_the_app_does(): void
    {
        // NotificationLink is a SECOND copy of the frontend resolver (PHP
        // cannot import the TS module). The announcement case is the only
        // one that maps anything, so it is the only one that can drift —
        // this asserts it produces exactly what
        // utils/notificationLink.ts builds for the same row.
        $agent = $this->agent();
        $announcement = $this->service()->notify(
            $agent,
            NotificationType::Announcement,
            'ประกาศทดสอบ',
            null,
            '/announcements',
            ['announcement_id' => 7],
        );

        $this->assertSame('/announcements?a=7', NotificationLink::for($announcement));

        // Legacy rows, and the no-destination case the SPA renders as a
        // non-navigating tap.
        $legacy = new NotificationRow(['type' => NotificationType::System, 'link' => '/news']);
        $this->assertSame('/announcements', NotificationLink::for($legacy));

        $nowhere = new NotificationRow(['type' => NotificationType::System, 'link' => null]);
        $this->assertNull(NotificationLink::for($nowhere));

        $external = new NotificationRow(['type' => NotificationType::System, 'link' => 'https://evil.example/x']);
        $this->assertNull(NotificationLink::for($external));
    }

    public function test_a_notification_with_no_destination_still_emails_without_a_button(): void
    {
        // The account-status mails are exactly the ones an agent most wants,
        // and some carry no link. A missing destination must cost the button,
        // not the email.
        $agent = $this->agent();
        $row = $this->service()->notify(
            $agent,
            NotificationType::ApprovalStatus,
            'สถานะบัญชีของคุณถูกเปลี่ยนแปลง',
            'เหตุผล: เอกสารไม่ครบ',
            null,
        );

        $mail = (new AgentNotificationEmail($row))->toMail($agent);

        $this->assertSame('สถานะบัญชีของคุณถูกเปลี่ยนแปลง', $mail->subject);
        $this->assertNull($mail->actionUrl);
    }

    public function test_the_mailer_itself_refuses_an_already_sent_row(): void
    {
        /*
         * Found by mutation-checking the case above, which turned out NOT to
         * cover this: deleting the mailer's `emailed_at !== null` guard left
         * "the sweep never sends twice" green, because the sweep's own query
         * filters on whereNull('emailed_at') and simply never re-selects the
         * row. The test was pinning the query, not the guard.
         *
         * The guard is still worth having and worth pinning. TWO paths reach
         * a row — the inline send at notify() time and the sweep — and only
         * the sweep filters. A retry, a scheduler overlap, or any future
         * caller holding a stale instance can present an already-sent row
         * directly, and the answer must be no.
         */
        Notification::fake();
        $agent = $this->agent();
        $row = $this->service()->notify($agent, NotificationType::CommissionPaid, 'ค่าคอมมิชชั่นจ่ายแล้ว', null, '/commission');

        // notify() already sent it inline; presenting it again must be a
        // refusal, not a second email.
        $this->assertFalse(app(NotificationMailer::class)->send($row->fresh()));

        Notification::assertSentToTimes($agent, AgentNotificationEmail::class, 1);
    }

    public function test_the_mailer_refuses_a_row_that_wants_no_email(): void
    {
        Notification::fake();
        $agent = $this->agent();
        $row = $this->service()->notify($agent, NotificationType::ExamPassed, 'สอบผ่าน', null, '/academy');

        $this->assertFalse(app(NotificationMailer::class)->send($row->fresh()));
        Notification::assertNothingSent();
    }
}
