<?php

namespace Tests\Feature\Platform;

use App\Mail\SmtpTestMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PlatformMailSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// TASK-201 — POST /api/v1/platform/mail-settings/test ("ทดสอบส่งอีเมล"
// button). Mail::fake() throughout — never attempts a real outbound SMTP
// connection in the test suite (per the task spec).
class SendTestMailTest extends TestCase
{
    use RefreshDatabase;

    private function enabledSettings(array $overrides = []): PlatformMailSetting
    {
        return PlatformMailSetting::query()->create(array_merge([
            'smtp_host' => 'smtp.hostinger.com',
            'smtp_port' => 465,
            'encryption' => 'ssl',
            'username' => 'noreply@syncvision.io',
            'password' => 'qa-fixture-not-a-real-secret',
            'from_address' => 'noreply@syncvision.io',
            'from_name' => 'SyncVision CRM',
            'is_enabled' => true,
        ], $overrides));
    }

    public function test_super_admin_can_send_a_test_mail_when_settings_are_enabled(): void
    {
        Mail::fake();

        $superAdmin = User::factory()->superAdmin()->create();
        $settings = $this->enabledSettings();

        $response = $this->actingAs($superAdmin)
            ->postJson('/api/v1/platform/mail-settings/test', ['to' => 'admin@example.com']);

        $response->assertOk()->assertJsonPath('message', 'ส่งอีเมลทดสอบสำเร็จ');

        Mail::assertSent(SmtpTestMail::class, function (SmtpTestMail $mail) {
            return $mail->hasTo('admin@example.com')
                && $mail->fromName === 'SyncVision CRM'
                && $mail->fromAddress === 'noreply@syncvision.io';
        });

        $audit = AuditLog::where('action', 'platform_mail_settings.test_sent')->firstOrFail();
        $this->assertSame($superAdmin->id, $audit->actor_user_id);
        $this->assertSame('admin@example.com', $audit->new_values['to']);
        $this->assertNull($audit->company_id);
        $this->assertSame($settings->id, $audit->auditable_id);
    }

    public function test_test_send_fails_with_422_when_no_settings_row_exists(): void
    {
        Mail::fake();

        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)
            ->postJson('/api/v1/platform/mail-settings/test', ['to' => 'admin@example.com']);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'กรุณาเปิดใช้งานและบันทึกการตั้งค่า SMTP ก่อนทดสอบส่งอีเมล');

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'platform_mail_settings.test_sent']);
    }

    public function test_test_send_fails_with_422_when_settings_are_disabled(): void
    {
        Mail::fake();

        $superAdmin = User::factory()->superAdmin()->create();
        $this->enabledSettings(['is_enabled' => false]);

        $response = $this->actingAs($superAdmin)
            ->postJson('/api/v1/platform/mail-settings/test', ['to' => 'admin@example.com']);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'กรุณาเปิดใช้งานและบันทึกการตั้งค่า SMTP ก่อนทดสอบส่งอีเมล');

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'platform_mail_settings.test_sent']);
    }

    public function test_company_admin_is_denied(): void
    {
        Mail::fake();

        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->enabledSettings();

        $this->actingAs($admin)
            ->postJson('/api/v1/platform/mail-settings/test', ['to' => 'admin@example.com'])
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_agent_is_denied(): void
    {
        Mail::fake();

        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->enabledSettings();

        $this->actingAs($agent)
            ->postJson('/api/v1/platform/mail-settings/test', ['to' => 'admin@example.com'])
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_to_field_is_required_and_must_be_a_valid_email(): void
    {
        Mail::fake();

        $superAdmin = User::factory()->superAdmin()->create();
        $this->enabledSettings();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/platform/mail-settings/test', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/platform/mail-settings/test', ['to' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');

        Mail::assertNothingSent();
    }
}
