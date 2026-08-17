<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PlatformMailSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// TASK-190 §3/§6 — the platform-wide SMTP settings screen's backend.
// Covers: Super-Admin-only access on both GET/PUT (Company Admin AND Agent
// denied), the password never appearing in plain in any API response, and
// the audit row never containing the password value.
class PlatformMailSettingTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'smtp_host' => 'smtp.hostinger.com',
            'smtp_port' => 465,
            'encryption' => 'ssl',
            'username' => 'noreply@syncvision.io',
            'password' => 'a-secret-password',
            'from_address' => 'noreply@syncvision.io',
            'from_name' => 'SyncVision CRM',
            'is_enabled' => true,
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // Super-Admin-only access (§6)
    // -----------------------------------------------------------------

    public function test_company_admin_is_denied_on_both_endpoints(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->getJson('/api/v1/platform/mail-settings')->assertForbidden();
        $this->actingAs($admin)->putJson('/api/v1/platform/mail-settings', $this->payload())->assertForbidden();
    }

    public function test_agent_is_denied_on_both_endpoints(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/platform/mail-settings')->assertForbidden();
        $this->actingAs($agent)->putJson('/api/v1/platform/mail-settings', $this->payload())->assertForbidden();
    }

    public function test_super_admin_can_read_and_write_the_settings(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->putJson('/api/v1/platform/mail-settings', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.smtp_host', 'smtp.hostinger.com')
            ->assertJsonPath('data.is_enabled', true);

        $this->actingAs($superAdmin)
            ->getJson('/api/v1/platform/mail-settings')
            ->assertOk()
            ->assertJsonPath('data.smtp_host', 'smtp.hostinger.com')
            ->assertJsonPath('data.from_name', 'SyncVision CRM');
    }

    // -----------------------------------------------------------------
    // Password never in plain, anywhere (§6)
    // -----------------------------------------------------------------

    // Deliberately NOT a real credential (ag-qa TASK-190 finding, 2026-08-16):
    // an earlier draft of this file used the actual SMTP password the human
    // shared in chat as this fixture, which put a real secret into a
    // versioned test file — exactly what CLAUDE.md §6 / this task's §1 says
    // must never happen. Every assertion below only needs SOME literal
    // string round-tripping correctly through encryption/masking/audit, not
    // the real one.
    private const TEST_PASSWORD_FIXTURE = 'qa-fixture-not-a-real-secret';

    public function test_password_never_appears_in_plain_in_any_api_response(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $updateResponse = $this->actingAs($superAdmin)
            ->putJson('/api/v1/platform/mail-settings', $this->payload(['password' => self::TEST_PASSWORD_FIXTURE]))
            ->assertOk();

        $this->assertStringNotContainsString(self::TEST_PASSWORD_FIXTURE, $updateResponse->getContent());
        $this->assertArrayNotHasKey('password', $updateResponse->json('data'));
        $this->assertTrue($updateResponse->json('data.password_set'));

        $showResponse = $this->actingAs($superAdmin)
            ->getJson('/api/v1/platform/mail-settings')
            ->assertOk();

        $this->assertStringNotContainsString(self::TEST_PASSWORD_FIXTURE, $showResponse->getContent());
        $this->assertArrayNotHasKey('password', $showResponse->json('data'));
        $this->assertTrue($showResponse->json('data.password_set'));

        // And never in plain at rest either — the 'encrypted' cast (TASK-044
        // bank_account_number precedent) means the raw DB column is never
        // the literal string.
        $raw = DB::table('platform_mail_settings')->value('password');
        $this->assertStringNotContainsString(self::TEST_PASSWORD_FIXTURE, (string) $raw);
    }

    public function test_re_saving_without_a_password_keeps_the_existing_one(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->putJson('/api/v1/platform/mail-settings', $this->payload(['password' => self::TEST_PASSWORD_FIXTURE]))
            ->assertOk();

        // Re-save toggling only is_enabled, password omitted entirely.
        $payloadWithoutPassword = $this->payload();
        unset($payloadWithoutPassword['password']);
        $payloadWithoutPassword['is_enabled'] = false;

        $this->actingAs($superAdmin)
            ->putJson('/api/v1/platform/mail-settings', $payloadWithoutPassword)
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.password_set', true);

        $this->assertSame(self::TEST_PASSWORD_FIXTURE, PlatformMailSetting::query()->first()->password);
    }

    public function test_audit_row_never_contains_the_password_value(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->putJson('/api/v1/platform/mail-settings', $this->payload(['password' => self::TEST_PASSWORD_FIXTURE]))
            ->assertOk();

        $audit = AuditLog::where('action', 'platform_mail_settings.updated')->firstOrFail();

        $this->assertStringNotContainsString(self::TEST_PASSWORD_FIXTURE, json_encode($audit->old_values));
        $this->assertStringNotContainsString(self::TEST_PASSWORD_FIXTURE, json_encode($audit->new_values));
        $this->assertArrayNotHasKey('password', $audit->new_values);
        $this->assertTrue($audit->new_values['password_set']);
        // Platform-level action, not tenant-scoped (AuditLog::company_id nullable).
        $this->assertNull($audit->company_id);
        $this->assertSame($superAdmin->id, $audit->actor_user_id);
    }
}
