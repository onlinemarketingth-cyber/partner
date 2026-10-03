<?php

namespace Tests\Feature\Platform;

use App\Models\AppVersionPolicy;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Platform\AppVersionPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 2026-10-02 — MOB-13. The mobile app's minimum / latest version per
 * platform.
 *
 * ── WHAT BREAKS SILENTLY HERE, AND WHY EACH CASE EXISTS ──
 *
 *  1. THE WHOLE FLEET LOCKED OUT. A minimum above the latest release forces
 *     every user to update to a version that does not exist. Nothing errors
 *     on the server; every phone shows a blocking "update" screen.
 *
 *  2. A COMPANY ADMIN LOCKS OUT OTHER COMPANIES. One binary serves every
 *     tenant, so this is Super Admin only, read included.
 *
 *  3. A STALE ANSWER. The public read is cached; a saved change that is not
 *     visible to the very next launch check is a release that "didn't work".
 *
 *  4. THE APP CANNOT ASK. The launch check runs before login; requiring auth
 *     there means an outdated app can never learn that it is outdated.
 */
class AppVersionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Array cache outlives the per-test rollback (see
        // PlatformCommissionSettingTest::setUp).
        Cache::forget(AppVersionPolicyService::CACHE_KEY);
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    // ── Public read ───────────────────────────────────────────────────────

    public function test_the_public_read_needs_no_login_and_starts_empty(): void
    {
        // Case 4. Seeded by the migration with every field null: no policy,
        // nobody blocked.
        $this->getJson('/api/v1/app/version-policy?platform=ios')
            ->assertOk()
            ->assertExactJson(['data' => [
                'platform' => 'ios',
                'min_supported_version' => null,
                'latest_version' => null,
                'store_url' => null,
            ]]);

        $this->assertSame(2, AppVersionPolicy::query()->count());
    }

    public function test_the_public_read_rejects_an_unknown_or_missing_platform(): void
    {
        $this->getJson('/api/v1/app/version-policy?platform=windows')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['platform']);

        $this->getJson('/api/v1/app/version-policy')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['platform']);
    }

    public function test_a_saved_change_is_visible_to_the_very_next_public_read(): void
    {
        // Case 3 — warm the cache first, then write.
        $this->getJson('/api/v1/app/version-policy?platform=android')->assertJsonPath('data.latest_version', null);

        $this->actingAs($this->superAdmin())->putJson('/api/v1/platform/app-version-policies', [
            'platform' => 'android',
            'min_supported_version' => '1.2.0',
            'latest_version' => '1.4.1',
            'store_url' => 'https://play.google.com/store/apps/details?id=club.liveto100',
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/app/version-policy?platform=android')
            ->assertOk()
            ->assertJsonPath('data.min_supported_version', '1.2.0')
            ->assertJsonPath('data.latest_version', '1.4.1')
            ->assertJsonPath('data.store_url', 'https://play.google.com/store/apps/details?id=club.liveto100');

        // The other platform is untouched.
        $this->getJson('/api/v1/app/version-policy?platform=ios')->assertJsonPath('data.latest_version', null);
    }

    // ── Super Admin screen ────────────────────────────────────────────────

    public function test_super_admin_lists_both_platforms_in_order(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/v1/platform/app-version-policies')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.platform', 'ios')
            ->assertJsonPath('data.1.platform', 'android');
    }

    public function test_company_admin_and_agent_can_neither_read_nor_write(): void
    {
        // Case 2.
        $company = Company::factory()->create();

        foreach ([
            User::factory()->companyAdmin()->create(['company_id' => $company->id]),
            User::factory()->agent()->create(['company_id' => $company->id]),
        ] as $user) {
            $this->actingAs($user)->getJson('/api/v1/platform/app-version-policies')->assertForbidden();
            $this->actingAs($user)
                ->putJson('/api/v1/platform/app-version-policies', ['platform' => 'ios', 'min_supported_version' => '9.9.9'])
                ->assertForbidden();
        }

        $this->assertNull(AppVersionPolicy::query()->where('platform', 'ios')->value('min_supported_version'));
        $this->assertSame(0, AuditLog::query()->where('action', 'app_version_policy.updated')->count());
    }

    public function test_the_screen_endpoints_require_login(): void
    {
        $this->getJson('/api/v1/platform/app-version-policies')->assertUnauthorized();
        $this->putJson('/api/v1/platform/app-version-policies', ['platform' => 'ios'])->assertUnauthorized();
    }

    public function test_an_update_is_audit_logged_with_old_and_new_values(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson('/api/v1/platform/app-version-policies', [
            'platform' => 'ios',
            'min_supported_version' => '1.0.0',
            'latest_version' => '1.1.0',
        ])
            ->assertOk()
            ->assertJsonPath('data.platform', 'ios')
            ->assertJsonPath('data.min_supported_version', '1.0.0');

        $log = AuditLog::query()->where('action', 'app_version_policy.updated')->sole();
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertNull($log->company_id);
        $this->assertSame(AppVersionPolicy::class, $log->auditable_type);
        $this->assertNull($log->old_values['min_supported_version']);
        $this->assertSame('1.0.0', $log->new_values['min_supported_version']);
        $this->assertSame('1.1.0', $log->new_values['latest_version']);
    }

    public function test_an_omitted_field_is_kept_and_an_explicit_null_clears(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->putJson('/api/v1/platform/app-version-policies', [
            'platform' => 'ios',
            'min_supported_version' => '1.0.0',
            'latest_version' => '1.3.0',
            'store_url' => 'https://apps.apple.com/app/id123',
        ])->assertOk();

        $this->actingAs($admin)->putJson('/api/v1/platform/app-version-policies', [
            'platform' => 'ios',
            'latest_version' => '1.4.0',
            'store_url' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.min_supported_version', '1.0.0')
            ->assertJsonPath('data.latest_version', '1.4.0')
            ->assertJsonPath('data.store_url', null);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidUpdates(): array
    {
        return [
            'unknown platform' => [['platform' => 'windows'], 'platform'],
            'missing platform' => [['latest_version' => '1.0.0'], 'platform'],
            'two-part version' => [['platform' => 'ios', 'latest_version' => '1.2'], 'latest_version'],
            'v-prefixed version' => [['platform' => 'ios', 'latest_version' => 'v1.2.3'], 'latest_version'],
            'leading zero' => [['platform' => 'ios', 'min_supported_version' => '1.02.0'], 'min_supported_version'],
            'pre-release suffix' => [['platform' => 'ios', 'min_supported_version' => '1.2.3-beta'], 'min_supported_version'],
            'plain http store url' => [['platform' => 'ios', 'store_url' => 'http://apps.apple.com/app/id1'], 'store_url'],
            'not a url' => [['platform' => 'ios', 'store_url' => 'apps.apple.com'], 'store_url'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidUpdates')]
    public function test_invalid_updates_are_rejected(array $payload, string $field): void
    {
        $this->actingAs($this->superAdmin())
            ->putJson('/api/v1/platform/app-version-policies', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    public function test_a_minimum_above_the_latest_is_rejected(): void
    {
        // Case 1.
        $this->actingAs($this->superAdmin())
            ->putJson('/api/v1/platform/app-version-policies', [
                'platform' => 'android',
                'min_supported_version' => '2.0.0',
                'latest_version' => '1.9.9',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['min_supported_version']);
    }

    public function test_a_minimum_above_the_stored_latest_is_rejected_even_when_sent_alone(): void
    {
        // Case 1, partial update: compared against what the row WILL hold.
        $admin = $this->superAdmin();
        $this->actingAs($admin)->putJson('/api/v1/platform/app-version-policies', [
            'platform' => 'android',
            'latest_version' => '1.10.0',
        ])->assertOk();

        // 1.9.0 < 1.10.0 numerically (a string comparison would say otherwise).
        $this->actingAs($admin)->putJson('/api/v1/platform/app-version-policies', [
            'platform' => 'android',
            'min_supported_version' => '1.9.0',
        ])->assertOk();

        $this->actingAs($admin)->putJson('/api/v1/platform/app-version-policies', [
            'platform' => 'android',
            'min_supported_version' => '1.11.0',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['min_supported_version']);

        $this->assertSame('1.9.0', AppVersionPolicy::query()->where('platform', 'android')->value('min_supported_version'));
    }
}
