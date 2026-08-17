<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PlatformCommissionSetting;
use App\Models\User;
use App\Services\Platform\PlatformCommissionSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// TASK-196 §2.1/§2.2/§2.4 — the platform-wide commission-rate-cap
// settings screen's backend. Covers: the cap defaulting to 30% (3000
// basis points) on a fresh migration with ZERO seeder calls (the
// migration itself seeds the row — §2.1), read reachable by any
// authenticated user (Company Admin included), write Super-Admin-only,
// and the write path being audit-logged.
class PlatformCommissionSettingTest extends TestCase
{
    use RefreshDatabase;

    // See CommissionRuleRateCapTest's own setUp() docblock — CACHE_STORE
    // is 'array' in phpunit.xml, which outlives a per-test DB rollback,
    // so every test here must start from a guaranteed-empty cache.
    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(PlatformCommissionSettingService::CACHE_KEY);
    }

    public function test_the_cap_defaults_to_thirty_percent_with_zero_seeder_calls(): void
    {
        // Deliberately NOT calling any Seeder — RefreshDatabase only runs
        // migrations, so this locks in §2.1's "every environment has a
        // cap from the moment this migrates" (the row is inserted inside
        // the migration's own up(), not a separate database/seeders
        // class).
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->getJson('/api/v1/platform/commission-cap')
            ->assertOk()
            ->assertJsonPath('data.max_commission_rate_basis_points', 3000);

        $this->assertSame(1, PlatformCommissionSetting::query()->count());
    }

    public function test_company_admin_can_read_but_not_write(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->getJson('/api/v1/platform/commission-cap')
            ->assertOk();

        $this->actingAs($admin)
            ->putJson('/api/v1/platform/commission-cap', ['max_commission_rate_basis_points' => 2000])
            ->assertForbidden();
    }

    public function test_agent_can_read_but_not_write(): void
    {
        // §2.2 — "read-everywhere" includes Agent too (same shape as
        // /cert-tiers): the 3 forms this cap gates are Admin-only, but
        // the read endpoint itself has no role gate beyond authentication.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->getJson('/api/v1/platform/commission-cap')
            ->assertOk()
            ->assertJsonPath('data.max_commission_rate_basis_points', 3000);

        $this->actingAs($agent)
            ->putJson('/api/v1/platform/commission-cap', ['max_commission_rate_basis_points' => 2000])
            ->assertForbidden();
    }

    public function test_super_admin_can_read_and_write(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->putJson('/api/v1/platform/commission-cap', ['max_commission_rate_basis_points' => 2500])
            ->assertOk()
            ->assertJsonPath('data.max_commission_rate_basis_points', 2500);

        $this->actingAs($superAdmin)
            ->getJson('/api/v1/platform/commission-cap')
            ->assertOk()
            ->assertJsonPath('data.max_commission_rate_basis_points', 2500);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/platform/commission-cap')->assertUnauthorized();
        $this->putJson('/api/v1/platform/commission-cap', ['max_commission_rate_basis_points' => 2500])->assertUnauthorized();
    }

    public function test_write_is_audit_logged(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->putJson('/api/v1/platform/commission-cap', ['max_commission_rate_basis_points' => 2500])
            ->assertOk();

        $audit = AuditLog::where('action', 'platform_commission_settings.updated')->firstOrFail();

        $this->assertSame(3000, $audit->old_values['max_commission_rate_basis_points']);
        $this->assertSame(2500, $audit->new_values['max_commission_rate_basis_points']);
        // Platform-level action, not tenant-scoped (AuditLog::company_id nullable).
        $this->assertNull($audit->company_id);
        $this->assertSame($superAdmin->id, $audit->actor_user_id);
    }

    public function test_value_over_one_hundred_percent_is_rejected(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->putJson('/api/v1/platform/commission-cap', ['max_commission_rate_basis_points' => 10001])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_commission_rate_basis_points');
    }
}
