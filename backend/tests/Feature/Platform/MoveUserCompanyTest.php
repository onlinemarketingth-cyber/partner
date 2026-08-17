<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Models\XpLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Phase 11 — Super-Admin-only (UserPolicy::move()). Historical
// commission_ledger/xp_ledger rows carry their own independent
// company_id (BR-4/BR-5) — a move must never rewrite them.
class MoveUserCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_move_an_agent_to_a_different_company(): void
    {
        $oldCompany = Company::factory()->create();
        $newCompany = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $oldCompany->id]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson("/api/v1/users/{$agent->id}/move-company", ['company_id' => $newCompany->id])
            ->assertOk()
            ->assertJsonPath('data.company.id', $newCompany->id);

        $this->assertDatabaseHas('users', ['id' => $agent->id, 'company_id' => $newCompany->id]);
    }

    public function test_move_writes_an_audit_log_entry_with_old_and_new_company(): void
    {
        $oldCompany = Company::factory()->create();
        $newCompany = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $oldCompany->id]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->postJson("/api/v1/users/{$agent->id}/move-company", ['company_id' => $newCompany->id])->assertOk();

        $log = AuditLog::where('auditable_type', User::class)->where('auditable_id', $agent->id)->where('action', 'move_to_company')->first();
        $this->assertNotNull($log);
        $this->assertSame($oldCompany->id, $log->old_values['company_id']);
        $this->assertSame($newCompany->id, $log->new_values['company_id']);
        $this->assertSame($superAdmin->id, $log->actor_user_id);
    }

    public function test_moving_a_user_does_not_rewrite_their_historical_ledger_company_id(): void
    {
        $oldCompany = Company::factory()->create();
        $newCompany = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $oldCompany->id]);
        XpLedger::factory()->create(['company_id' => $oldCompany->id, 'user_id' => $agent->id, 'xp_awarded' => 50]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->postJson("/api/v1/users/{$agent->id}/move-company", ['company_id' => $newCompany->id])->assertOk();

        $this->assertDatabaseHas('xp_ledger', ['user_id' => $agent->id, 'company_id' => $oldCompany->id]);
    }

    public function test_company_admin_cannot_move_a_user(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/users/{$agent->id}/move-company", ['company_id' => $otherCompany->id])
            ->assertForbidden();
    }

    public function test_super_admin_cannot_move_another_super_admin(): void
    {
        $newCompany = Company::factory()->create();
        $otherSuperAdmin = User::factory()->superAdmin()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson("/api/v1/users/{$otherSuperAdmin->id}/move-company", ['company_id' => $newCompany->id])
            ->assertForbidden();
    }

    public function test_company_id_must_reference_a_real_company(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson("/api/v1/users/{$agent->id}/move-company", ['company_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }
}
