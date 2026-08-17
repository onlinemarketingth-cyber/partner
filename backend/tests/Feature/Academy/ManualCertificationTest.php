<?php

namespace Tests\Feature\Academy;

use App\Models\AuditLog;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// BR-1 admin override (human-requested 2026-07-30) — a Company Admin/Super
// Admin manually grants a cert tier without a real exam attempt. See
// ManualCertificationService's own docblock for why no XP is awarded.
class ManualCertificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_grant_certifications(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $target = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create(['key' => 'basic']);

        $this->actingAs($agent)->postJson('/api/v1/user-certifications', [
            'user_id' => $target->id,
            'cert_tier_id' => $tier->id,
        ])->assertForbidden();
    }

    public function test_company_admin_can_grant_a_certification_to_their_own_agent(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $target = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create(['key' => 'basic']);

        $this->actingAs($admin)->postJson('/api/v1/user-certifications', [
            'user_id' => $target->id,
            'cert_tier_id' => $tier->id,
        ])->assertCreated()
            ->assertJsonPath('data.cert_tier.id', $tier->id);

        $this->assertDatabaseHas('user_certifications', [
            'company_id' => $company->id,
            'user_id' => $target->id,
            'cert_tier_id' => $tier->id,
            'exam_attempt_id' => null,
        ]);

        $this->assertTrue($target->fresh()->hasPassedCertTier('basic'));
    }

    public function test_grant_writes_an_audit_log_entry(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $target = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create(['key' => 'basic']);

        $this->actingAs($admin)->postJson('/api/v1/user-certifications', [
            'user_id' => $target->id,
            'cert_tier_id' => $tier->id,
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id,
            'actor_user_id' => $admin->id,
            'action' => 'user_certification.manual_grant',
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
        ]);
    }

    public function test_company_admin_cannot_grant_a_certification_to_another_companys_agent(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $target = User::factory()->agent()->create(['company_id' => $otherCompany->id]);
        $tier = CertTier::factory()->create(['key' => 'basic']);

        $this->actingAs($admin)->postJson('/api/v1/user-certifications', [
            'user_id' => $target->id,
            'cert_tier_id' => $tier->id,
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('user_certifications', ['user_id' => $target->id]);
    }

    public function test_super_admin_can_grant_a_certification_across_companies(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create(['company_id' => null]);
        $target = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create(['key' => 'basic']);

        $this->actingAs($superAdmin)->postJson('/api/v1/user-certifications', [
            'user_id' => $target->id,
            'cert_tier_id' => $tier->id,
        ])->assertCreated();

        $this->assertDatabaseHas('user_certifications', ['company_id' => $company->id, 'user_id' => $target->id]);
    }

    public function test_granting_an_already_certified_agent_is_idempotent(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $target = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create(['key' => 'basic']);

        $this->actingAs($admin)->postJson('/api/v1/user-certifications', [
            'user_id' => $target->id,
            'cert_tier_id' => $tier->id,
        ])->assertCreated();

        // Second grant for the same (user, tier) must not violate the
        // unique(user_id, cert_tier_id) constraint or double-log.
        $this->actingAs($admin)->postJson('/api/v1/user-certifications', [
            'user_id' => $target->id,
            'cert_tier_id' => $tier->id,
        ])->assertOk();

        $this->assertSame(1, UserCertification::where('user_id', $target->id)->where('cert_tier_id', $tier->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'user_certification.manual_grant')->where('auditable_id', $target->id)->count());
    }

    public function test_cannot_grant_a_certification_to_a_company_admin(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $otherAdmin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create(['key' => 'basic']);

        $this->actingAs($admin)->postJson('/api/v1/user-certifications', [
            'user_id' => $otherAdmin->id,
            'cert_tier_id' => $tier->id,
        ])->assertUnprocessable();
    }

    public function test_unknown_cert_tier_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $target = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/user-certifications', [
            'user_id' => $target->id,
            'cert_tier_id' => 999999,
        ])->assertUnprocessable();
    }
}
