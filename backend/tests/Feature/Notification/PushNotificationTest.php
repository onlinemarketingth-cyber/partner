<?php

namespace Tests\Feature\Notification;

use App\Enums\DevicePlatform;
use App\Enums\NotificationType;
use App\Jobs\SendPushNotification;
use App\Models\Company;
use App\Models\DeviceToken;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Notifications\AgentNotificationEmail;
use App\Services\Notification\NotificationService;
use App\Services\Notification\Push\FcmClient;
use App\Services\Notification\Push\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * 2026-10-02 — MOB-11. Every bell notification is also pushed to the
 * recipient's phones through FCM HTTP v1.
 *
 * ── WHAT BREAKS SILENTLY HERE, AND WHY EACH CASE EXISTS ──
 *
 *  1. A CLIENT'S NAME ON A LOCK SCREEN. The notification's title/body can
 *     name a client (PDPA, CLAUDE.md §6). Copying them into the push is the
 *     obvious implementation and nothing would ever error. The push must
 *     carry only the app label and the per-type sentence.
 *
 *  2. A BROKEN FIREBASE TAKES NOTIFICATIONS DOWN WITH IT. notify() runs
 *     inside payment and commission transactions. A push failure that
 *     throws there would roll back a ledger row; one that throws in the
 *     afterCommit chain could swallow the email.
 *
 *  3. DEAD TOKENS ARE TRIED FOREVER — and, the other way round, a payload
 *     bug of ours answered with INVALID_ARGUMENT deletes every device on the
 *     platform. Only a token FCM blames is deleted.
 *
 *  4. A PUSH FOR SOMETHING THAT NEVER HAPPENED. A rolled-back transaction
 *     must not have buzzed anyone's phone.
 *
 *  5. AN OFF-SITE TAP. The url in the data payload is opened by the app; an
 *     absolute URL in `link` must never become a redirect out of the app.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_NAME = 'บริษัท ไทยประกันชีวิต จำกัด (มหาชน)';

    private const FCM_URL = 'https://fcm.googleapis.com/v1/projects/demo-project/messages:send';

    private string $credentialsPath = '';

    private string $publicKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        // The access token is cached; every test starts without one.
        Cache::flush();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $privatePem);
        $this->publicKey = openssl_pkey_get_details($key)['key'];

        $this->credentialsPath = (string) tempnam(sys_get_temp_dir(), 'fcm-sa-');
        file_put_contents($this->credentialsPath, json_encode([
            'type' => 'service_account',
            'project_id' => 'demo-project',
            'client_email' => 'push@demo-project.iam.gserviceaccount.com',
            'private_key' => $privatePem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config([
            'services.firebase.project_id' => 'demo-project',
            'services.firebase.credentials' => $this->credentialsPath,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->credentialsPath !== '' && is_file($this->credentialsPath)) {
            unlink($this->credentialsPath);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>|callable|null  $fcmResponse
     */
    private function fakeGoogle(mixed $fcmResponse = null): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test-token', 'expires_in' => 3599, 'token_type' => 'Bearer']),
            'fcm.googleapis.com/*' => $fcmResponse ?? Http::response(['name' => 'projects/demo-project/messages/1']),
        ]);
    }

    private function agentWithPhones(string ...$tokens): User
    {
        return $this->agentOf(Company::factory()->create(['name' => self::COMPANY_NAME]), ...$tokens);
    }

    private function agentOf(Company $company, string ...$tokens): User
    {
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        foreach ($tokens as $i => $token) {
            DeviceToken::create([
                'company_id' => $company->id,
                'user_id' => $agent->id,
                'platform' => $i % 2 === 0 ? DevicePlatform::Ios : DevicePlatform::Android,
                'token' => $token,
                'token_hash' => DeviceToken::hashToken($token),
                'last_seen_at' => now(),
            ]);
        }

        return $agent;
    }

    private function notify(User $user, NotificationType $type = NotificationType::OrderPaymentConfirmed, string $title = 'คุณสมชาย ใจดี ชำระเงินแล้ว', ?string $body = 'ลูกค้า สมชาย ใจดี ชำระ 9,900 บาท', ?string $link = '/clients/42', ?array $data = null): NotificationRow
    {
        return app(NotificationService::class)->notify($user, $type, $title, $body, $link, $data);
    }

    /** @return list<Request> */
    private function fcmRequests(): array
    {
        return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://fcm.googleapis.com/'))
            ->map(fn (array $pair) => $pair[0])
            ->values()
            ->all();
    }

    private function hasToken(string $token): bool
    {
        return DeviceToken::withoutGlobalScopes()->where('token_hash', DeviceToken::hashToken($token))->exists();
    }

    // ── What is sent ──────────────────────────────────────────────────────

    public function test_each_phone_gets_a_push_titled_with_the_company_and_the_type_sentence(): void
    {
        $this->fakeGoogle();
        $agent = $this->agentWithPhones('token-ios', 'token-android');

        $row = $this->notify($agent);

        $requests = $this->fcmRequests();
        $this->assertCount(2, $requests);
        $this->assertEqualsCanonicalizing(['token-ios', 'token-android'], array_map(fn (Request $r) => $r['message']['token'], $requests));

        $message = $requests[0]['message'];
        $this->assertSame(self::FCM_URL, $requests[0]->url());
        $this->assertTrue($requests[0]->hasHeader('Authorization', 'Bearer ya29.test-token'));
        // Owner 2026-10-03: the title is the recipient's company name.
        $this->assertSame(self::COMPANY_NAME, $message['notification']['title']);
        $this->assertSame('มีการยืนยันการชำระเงินของลูกค้า', $message['notification']['body']);
        $this->assertSame(['notification_id' => (string) $row->id, 'url' => '/clients/42'], $message['data']);
        $this->assertSame('high', $message['android']['priority']);
        $this->assertSame('default', $message['apns']['payload']['aps']['sound']);
        $this->assertSame(self::COMPANY_NAME, $message['apns']['payload']['aps']['alert']['title']);
        $this->assertSame('10', $message['apns']['headers']['apns-priority']);
    }

    public function test_the_push_never_contains_the_notifications_own_text(): void
    {
        // Case 1 — PDPA. Searched across the WHOLE request body, apns and
        // android blocks included, not just notification.title/body.
        $this->fakeGoogle();
        $agent = $this->agentWithPhones('token-ios', 'token-android');

        $this->notify($agent, NotificationType::System, 'ลูกค้า นางสาวมาลี ศรีสุข', 'ผลตรวจสุขภาพของ มาลี พร้อมแล้ว');

        foreach ($this->fcmRequests() as $request) {
            $raw = $request->body();
            $this->assertStringNotContainsString('มาลี', $raw);
            $this->assertStringNotContainsString('ศรีสุข', $raw);
            $this->assertStringNotContainsString('ผลตรวจสุขภาพ', $raw);
            $this->assertSame('มีการแจ้งเตือนใหม่', $request['message']['notification']['body']);
        }
    }

    // ── The title: the recipient's company (owner decision 2026-10-03) ──

    public function test_recipients_in_different_companies_each_see_their_own_company(): void
    {
        $this->fakeGoogle();
        $thaiLife = $this->agentOf(Company::factory()->create(['name' => 'Thai Life']), 'token-thai-life');
        $genesenn = $this->agentOf(Company::factory()->create(['name' => 'GENESENN Health']), 'token-genesenn');

        $this->notify($thaiLife);
        $this->notify($genesenn);

        $titles = [];
        foreach ($this->fcmRequests() as $request) {
            $titles[$request['message']['token']] = $request['message']['notification']['title'];
            $this->assertSame(
                $request['message']['notification']['title'],
                $request['message']['apns']['payload']['aps']['alert']['title'],
            );
        }

        $this->assertSame(['token-thai-life' => 'Thai Life', 'token-genesenn' => 'GENESENN Health'], $titles);
    }

    public function test_the_title_follows_the_notifications_company_not_the_acting_user(): void
    {
        // notify() is usually called while SOMEONE ELSE is logged in (an admin
        // approving an agent). The title must still be the recipient's.
        $this->fakeGoogle();
        $agent = $this->agentWithPhones('token-ios');
        $this->actingAs(User::factory()->companyAdmin()->create([
            'company_id' => Company::factory()->create(['name' => 'Another Company'])->id,
        ]));

        $this->notify($agent);

        $this->assertSame(self::COMPANY_NAME, $this->fcmRequests()[0]['message']['notification']['title']);
    }

    public function test_a_blank_company_name_falls_back_to_the_configured_title(): void
    {
        config(['notifications.push.title' => 'Fallback Club']);
        $this->fakeGoogle();
        $agent = $this->agentOf(Company::factory()->create(['name' => "  \n "]), 'token-ios');

        $this->notify($agent);

        $this->assertSame('Fallback Club', $this->fcmRequests()[0]['message']['notification']['title']);
    }

    public function test_a_missing_company_falls_back_to_the_configured_title(): void
    {
        config(['notifications.push.title' => 'Fallback Club']);
        $orphan = (new NotificationRow)->forceFill(['company_id' => 999999, 'type' => NotificationType::System]);

        $this->assertSame('Fallback Club', app(PushNotificationService::class)->titleFor($orphan));
    }

    public function test_a_long_or_multi_line_company_name_is_collapsed_and_trimmed(): void
    {
        $this->fakeGoogle();
        $agent = $this->agentOf(Company::factory()->create(['name' => "บริษัท\nทดสอบ   ".str_repeat('ก', 100)]), 'token-ios');

        $this->notify($agent);

        $title = $this->fcmRequests()[0]['message']['notification']['title'];
        $this->assertSame(PushNotificationService::TITLE_MAX_CHARS, mb_strlen($title));
        $this->assertStringStartsWith('บริษัท ทดสอบ ก', $title);
        $this->assertStringEndsWith('…', $title);
        $this->assertStringNotContainsString("\n", $title);
    }

    public function test_an_unmapped_type_falls_back_to_the_neutral_sentence(): void
    {
        config(['notifications.push.bodies' => []]);
        $this->fakeGoogle();
        $agent = $this->agentWithPhones('token-ios');

        $this->notify($agent, NotificationType::CommissionPaid, 'ได้รับ 1,250 บาท');

        $this->assertSame('มีการแจ้งเตือนใหม่', $this->fcmRequests()[0]['message']['notification']['body']);
    }

    public function test_the_tap_url_is_always_an_in_app_path(): void
    {
        // Case 5.
        $this->fakeGoogle();
        $agent = $this->agentWithPhones('token-ios');

        $this->notify($agent, link: 'https://evil.example/phish');
        $this->notify($agent, link: '//evil.example/phish');
        $this->notify($agent, link: null);
        $this->notify($agent, NotificationType::Announcement, 'ประกาศ', null, '/announcements', ['announcement_id' => 7]);

        $urls = array_map(fn (Request $r) => $r['message']['data']['url'], $this->fcmRequests());
        $this->assertSame(['/notifications', '/notifications', '/notifications', '/announcements?a=7'], $urls);
    }

    public function test_the_oauth_assertion_is_a_valid_rs256_jwt_and_the_token_is_cached(): void
    {
        $this->fakeGoogle();
        $agent = $this->agentWithPhones('token-ios');

        $this->notify($agent);
        $this->notify($agent);

        $oauth = Http::recorded(fn (Request $r) => $r->url() === FcmClient::TOKEN_URI)->map(fn ($p) => $p[0])->values();
        $this->assertCount(1, $oauth, 'the access token must be reused, not fetched per message');
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $oauth[0]['grant_type']);

        [$header, $claims, $signature] = explode('.', $oauth[0]['assertion']);
        $decode = fn (string $s) => base64_decode(strtr($s, '-_', '+/'));
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode($decode($header), true));

        $payload = json_decode($decode($claims), true);
        $this->assertSame('push@demo-project.iam.gserviceaccount.com', $payload['iss']);
        $this->assertSame(FcmClient::SCOPE, $payload['scope']);
        $this->assertSame(FcmClient::TOKEN_URI, $payload['aud']);
        $this->assertSame(3600, $payload['exp'] - $payload['iat']);

        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $decode($signature), $this->publicKey, OPENSSL_ALGO_SHA256));
        $this->assertCount(2, $this->fcmRequests());
    }

    public function test_a_401_refreshes_the_access_token_and_retries_once(): void
    {
        $this->fakeGoogle(Http::sequence()
            ->push(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401)
            ->push(['name' => 'projects/demo-project/messages/2']));
        $agent = $this->agentWithPhones('token-ios');

        $this->notify($agent);

        $this->assertCount(2, $this->fcmRequests());
        $this->assertCount(2, Http::recorded(fn (Request $r) => $r->url() === FcmClient::TOKEN_URI));
        $this->assertTrue($this->hasToken('token-ios'));
    }

    // ── Dead tokens ───────────────────────────────────────────────────────

    public function test_an_unregistered_token_is_deleted_and_the_others_are_kept(): void
    {
        $this->fakeGoogle(function (Request $request) {
            if ($request['message']['token'] === 'token-dead') {
                return Http::response(['error' => [
                    'code' => 404,
                    'message' => 'Requested entity was not found.',
                    'status' => 'NOT_FOUND',
                    'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
                ]], 404);
            }

            return Http::response(['name' => 'projects/demo-project/messages/1']);
        });
        $agent = $this->agentWithPhones('token-alive', 'token-dead');

        $this->notify($agent);

        $this->assertFalse($this->hasToken('token-dead'));
        $this->assertTrue($this->hasToken('token-alive'));
    }

    public function test_an_invalid_registration_token_is_deleted(): void
    {
        $this->fakeGoogle(Http::response(['error' => [
            'code' => 400,
            'message' => 'The registration token is not a valid FCM registration token',
            'status' => 'INVALID_ARGUMENT',
            'details' => [
                ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'INVALID_ARGUMENT'],
                ['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => [['field' => 'message.token', 'description' => 'Invalid registration token']]],
            ],
        ]], 400));
        $agent = $this->agentWithPhones('token-garbage');

        $this->notify($agent);

        $this->assertFalse($this->hasToken('token-garbage'));
    }

    public function test_an_invalid_argument_about_the_payload_does_not_delete_the_token(): void
    {
        // Case 3, the other direction: our bug, not the token's.
        $this->fakeGoogle(Http::response(['error' => [
            'code' => 400,
            'message' => 'Invalid value at \'message.android.priority\'',
            'status' => 'INVALID_ARGUMENT',
            'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => [['field' => 'message.android.priority']]],
            ],
        ]], 400));
        $agent = $this->agentWithPhones('token-fine');

        $this->notify($agent);

        $this->assertTrue($this->hasToken('token-fine'));
    }

    public function test_a_server_error_keeps_the_token(): void
    {
        $this->fakeGoogle(Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503));
        $agent = $this->agentWithPhones('token-ios');

        $this->notify($agent);

        $this->assertTrue($this->hasToken('token-ios'));
    }

    // ── Push can never hurt the notification or the email ─────────────────

    public function test_a_push_failure_leaves_the_notification_and_the_email_intact(): void
    {
        // Case 2. ApprovalStatus emails inline, so both channels run.
        Notification::fake();
        $this->fakeGoogle(fn () => throw new ConnectionException('FCM unreachable'));
        $agent = $this->agentWithPhones('token-ios');

        $row = $this->notify($agent, NotificationType::ApprovalStatus, 'บัญชีของคุณได้รับการอนุมัติแล้ว', null, '/');

        $this->assertDatabaseHas('notifications', ['id' => $row->id]);
        Notification::assertSentTo($agent, AgentNotificationEmail::class);
        $this->assertNotNull($row->fresh()->emailed_at);
        $this->assertTrue($this->hasToken('token-ios'));
    }

    public function test_an_unreachable_oauth_endpoint_does_not_throw_into_notify(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => fn () => throw new ConnectionException('no route'),
            'fcm.googleapis.com/*' => Http::response([]),
        ]);
        $agent = $this->agentWithPhones('token-ios');

        $row = $this->notify($agent);

        $this->assertDatabaseHas('notifications', ['id' => $row->id]);
        $this->assertSame([], $this->fcmRequests());
    }

    public function test_nothing_is_pushed_for_a_transaction_that_rolled_back(): void
    {
        // Case 4 — same afterCommit contract as the email.
        $this->fakeGoogle();
        $agent = $this->agentWithPhones('token-ios');

        try {
            DB::transaction(function () use ($agent) {
                $this->notify($agent);

                throw new RuntimeException('ledger write failed');
            });
        } catch (RuntimeException) {
            // expected
        }

        Http::assertNothingSent();
    }

    // ── When push is off, or there is no phone ────────────────────────────

    public function test_push_is_off_when_firebase_is_not_configured(): void
    {
        config(['services.firebase.project_id' => null, 'services.firebase.credentials' => null]);
        Queue::fake();
        Http::fake();
        $agent = $this->agentWithPhones('token-ios');

        $row = $this->notify($agent);

        $this->assertDatabaseHas('notifications', ['id' => $row->id]);
        Queue::assertNotPushed(SendPushNotification::class);
        Http::assertNothingSent();
    }

    public function test_push_is_off_when_the_credentials_file_is_missing(): void
    {
        config(['services.firebase.credentials' => '/nonexistent/service-account.json']);
        Queue::fake();
        $agent = $this->agentWithPhones('token-ios');

        $this->notify($agent);

        Queue::assertNotPushed(SendPushNotification::class);
    }

    public function test_a_broken_credentials_file_sends_nothing_and_throws_nothing(): void
    {
        file_put_contents($this->credentialsPath, '{"client_email": "x@y", "private_key": "not a key"}');
        Http::fake();
        $agent = $this->agentWithPhones('token-ios');

        $row = $this->notify($agent);

        $this->assertDatabaseHas('notifications', ['id' => $row->id]);
        Http::assertNothingSent();
    }

    public function test_no_job_is_queued_for_a_user_without_a_phone(): void
    {
        Queue::fake();
        $agent = $this->agentWithPhones();

        $this->notify($agent);

        Queue::assertNotPushed(SendPushNotification::class);
    }

    public function test_a_job_is_queued_once_per_notification_for_a_user_with_phones(): void
    {
        Queue::fake();
        $agent = $this->agentWithPhones('token-ios', 'token-android');

        $row = $this->notify($agent);

        Queue::assertPushed(SendPushNotification::class, 1);
        Queue::assertPushed(SendPushNotification::class, fn (SendPushNotification $job) => $job->notificationId === $row->id);
    }

    public function test_only_the_recipients_phones_are_pushed(): void
    {
        // Tenant/user isolation on the send side: a colleague's and another
        // company's phones never receive this user's push.
        $this->fakeGoogle();
        $recipient = $this->agentWithPhones('token-recipient');
        $this->agentWithPhones('token-other-company');
        DeviceToken::create([
            'company_id' => $recipient->company_id,
            'user_id' => User::factory()->agent()->create(['company_id' => $recipient->company_id])->id,
            'platform' => DevicePlatform::Ios,
            'token' => 'token-colleague',
            'token_hash' => DeviceToken::hashToken('token-colleague'),
        ]);

        $this->notify($recipient);

        $this->assertSame(['token-recipient'], array_map(fn (Request $r) => $r['message']['token'], $this->fcmRequests()));
    }

    // ── The job's own guards ──────────────────────────────────────────────

    public function test_the_job_skips_a_notification_already_read_or_stale(): void
    {
        Queue::fake();
        $agent = $this->agentWithPhones('token-ios');
        $read = $this->notify($agent);
        $read->forceFill(['read_at' => now()])->save();
        $stale = $this->notify($agent);
        $stale->forceFill(['created_at' => now()->subHours(3)])->save();

        $this->fakeGoogle();
        app()->call([new SendPushNotification($read->id), 'handle']);
        app()->call([new SendPushNotification($stale->id), 'handle']);
        app()->call([new SendPushNotification(999999), 'handle']);

        Http::assertNothingSent();
    }

    public function test_the_job_skips_a_deactivated_recipient(): void
    {
        Queue::fake();
        $agent = $this->agentWithPhones('token-ios');
        $row = $this->notify($agent);
        $agent->delete();

        $this->fakeGoogle();
        app()->call([new SendPushNotification($row->id), 'handle']);

        Http::assertNothingSent();
    }
}
