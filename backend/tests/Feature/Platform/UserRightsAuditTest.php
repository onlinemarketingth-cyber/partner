<?php

namespace Tests\Feature\Platform;

use App\Models\AgentInviteLink;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TASK-183 §4 — the six rights-affecting writes that used to leave no trace,
 * plus the §4.4 self-service password change.
 *
 * CLAUDE.md §6 requires an audit row for "every action that affects money,
 * commission, status, certification, or permissions". Bank-account and
 * national-ID changes were already audited, so the writer, the shape and the
 * masking helpers all existed — these tests pin the extension of that pattern
 * to `role`, `is_team_leader`, `manager_id`, creation, deactivation, restore,
 * and both password paths.
 *
 * Every test goes through the REAL HTTP endpoint rather than calling the
 * Service, for the reason LoginGateTest gives about its own regression test:
 * a Service-level assertion pins the Service's behaviour for a shape the test
 * author believed the endpoint produces, and the regression usually lives in
 * the gap between the two.
 */
class UserRightsAuditTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);
    }

    private function auditRow(string $action, User $target): AuditLog
    {
        $row = AuditLog::where('action', $action)
            ->where('auditable_type', User::class)
            ->where('auditable_id', $target->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($row, "Expected an audit_logs row with action [{$action}] for user {$target->id}.");

        return $row;
    }

    // ── user.created ──────────────────────────────────────────────────────

    public function test_creating_a_user_writes_an_audit_row_with_the_actor_and_the_granted_rights(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/users', [
                'first_name' => 'Somchai',
                'last_name' => 'Jaidee',
                'email' => 'somchai@thailife.test',
                'password' => 'TempPass123',
                'role' => 'agent',
            ])
            ->assertCreated();

        $created = User::withoutGlobalScopes()->where('email', 'somchai@thailife.test')->firstOrFail();
        $row = $this->auditRow('user.created', $created);

        $this->assertSame($this->admin->id, $row->actor_user_id);
        $this->assertSame($this->company->id, $row->company_id);
        $this->assertNull($row->old_values);
        $this->assertSame('agent', $row->new_values['role']);
        $this->assertFalse($row->new_values['is_team_leader']);
        $this->assertNull($row->new_values['manager_id']);
    }

    /**
     * §4.2 on the CREATE path too. StoreUserRequest accepts a temporary
     * password, and the naive implementation of this row is
     * `'new_values' => $data` — which would put a plaintext credential into
     * the table Company Admins can read through GET /audit-logs.
     */
    public function test_creating_a_user_never_records_the_temporary_password(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/users', [
                'first_name' => 'Somchai',
                'last_name' => 'Jaidee',
                'email' => 'somchai@thailife.test',
                'password' => 'Sup3rSecretTemp!',
                'role' => 'agent',
            ])
            ->assertCreated();

        $created = User::withoutGlobalScopes()->where('email', 'somchai@thailife.test')->firstOrFail();
        $row = $this->auditRow('user.created', $created);

        $this->assertStringNotContainsString('Sup3rSecretTemp!', json_encode($row->getAttributes()));
        $this->assertArrayNotHasKey('password', (array) $row->new_values);
    }

    // ── user.role_changed / user.team_leader_changed / user.manager_changed ─

    public function test_changing_a_role_writes_an_audit_row_with_old_and_new(): void
    {
        $agent = User::factory()->agent()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->admin)
            ->putJson('/api/v1/users/'.$agent->id, ['role' => 'company_admin'])
            ->assertOk();

        $row = $this->auditRow('user.role_changed', $agent);

        $this->assertSame($this->admin->id, $row->actor_user_id);
        $this->assertSame(['role' => 'agent'], $row->old_values);
        $this->assertSame(['role' => 'company_admin'], $row->new_values);
    }

    /** ADR-025 §1 — the most permission-like write in the system. */
    public function test_granting_and_revoking_team_leader_each_write_a_row(): void
    {
        $agent = User::factory()->agent()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->admin)
            ->putJson('/api/v1/users/'.$agent->id, ['is_team_leader' => true])
            ->assertOk();

        $granted = $this->auditRow('user.team_leader_changed', $agent);
        $this->assertSame(['is_team_leader' => false], $granted->old_values);
        $this->assertSame(['is_team_leader' => true], $granted->new_values);

        $this->actingAs($this->admin)
            ->putJson('/api/v1/users/'.$agent->id, ['is_team_leader' => false])
            ->assertOk();

        $revoked = $this->auditRow('user.team_leader_changed', $agent);
        $this->assertNotSame($granted->id, $revoked->id, 'The revoke must be its own row, not an edit of the grant.');
        $this->assertSame(['is_team_leader' => true], $revoked->old_values);
        $this->assertSame(['is_team_leader' => false], $revoked->new_values);
    }

    public function test_changing_a_manager_writes_an_audit_row_on_the_admin_path(): void
    {
        $manager = User::factory()->agent()->create(['company_id' => $this->company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->admin)
            ->putJson('/api/v1/users/'.$agent->id, ['manager_id' => $manager->id])
            ->assertOk();

        $row = $this->auditRow('user.manager_changed', $agent);

        $this->assertSame($this->admin->id, $row->actor_user_id);
        $this->assertSame(['manager_id' => null], $row->old_values);
        $this->assertSame(['manager_id' => $manager->id], $row->new_values);
    }

    /**
     * The OTHER manager_id write path — RegistrationService's recruit-link
     * signup, which goes through UserService::assignManager(). It must land on
     * the SAME action name (a filter that silently missed half the writes
     * would be worse than no filter), with a NULL actor: nobody is acting on a
     * public, unauthenticated registration.
     */
    public function test_a_recruit_link_signup_writes_the_same_manager_changed_action_with_a_null_actor(): void
    {
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $this->company->id]);
        $link = AgentInviteLink::factory()->create([
            'company_id' => $this->company->id,
            'agent_id' => $leader->id,
        ]);

        $this->postJson('/api/v1/register', [
            'ref_token' => $link->token,
            'first_name' => 'Somsri',
            'last_name' => 'Dee',
            'email' => 'somsri@thailife.test',
            'id_document_type' => 'thai_national_id',
            'national_id' => '1234567890121',
            'password' => 'TempPass123!',
            'password_confirmation' => 'TempPass123!',
        ])->assertCreated();

        $recruit = User::withoutGlobalScopes()->where('email', 'somsri@thailife.test')->firstOrFail();
        $row = $this->auditRow('user.manager_changed', $recruit);

        $this->assertNull($row->actor_user_id);
        $this->assertSame(['manager_id' => null], $row->old_values);
        $this->assertSame(['manager_id' => $leader->id], $row->new_values);
    }

    /** An edit that changes nothing rights-related must not manufacture rows. */
    public function test_editing_only_the_name_writes_no_rights_audit_rows(): void
    {
        $agent = User::factory()->agent()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->admin)
            ->putJson('/api/v1/users/'.$agent->id, ['first_name' => 'Renamed'])
            ->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.role_changed', 'auditable_id' => $agent->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.team_leader_changed', 'auditable_id' => $agent->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.manager_changed', 'auditable_id' => $agent->id]);
    }

    // ── user.deactivated / user.restored ──────────────────────────────────

    public function test_deactivating_and_restoring_a_user_each_write_a_row(): void
    {
        $agent = User::factory()->agent()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->admin)->deleteJson('/api/v1/users/'.$agent->id)->assertNoContent();

        $deactivated = $this->auditRow('user.deactivated', $agent);
        $this->assertSame($this->admin->id, $deactivated->actor_user_id);
        $this->assertSame(['deleted_at' => null], $deactivated->old_values);
        $this->assertNotNull($deactivated->new_values['deleted_at']);

        $this->actingAs($this->admin)->postJson('/api/v1/users/'.$agent->id.'/restore')->assertOk();

        $restored = $this->auditRow('user.restored', $agent);
        $this->assertSame($this->admin->id, $restored->actor_user_id);
        $this->assertNotNull($restored->old_values['deleted_at']);
        $this->assertSame(['deleted_at' => null], $restored->new_values);
    }

    // ── §4.2 — password rows carry no password material ───────────────────

    /**
     * THE §4.2 TEST. The row must record THAT a reset happened and BY WHOM,
     * and must contain neither the submitted password nor the bcrypt hash it
     * produced. The hash matters as much as the plaintext: audit_logs is
     * readable by every Company Admin through GET /audit-logs, a wider
     * audience than the `users` row it came from, and a bcrypt hash is an
     * offline-crackable artefact.
     */
    public function test_an_admin_password_reset_is_audited_without_any_password_material(): void
    {
        $agent = User::factory()->agent()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/users/'.$agent->id.'/reset-password', [
                'password' => 'BrandNewSecret123!',
                'password_confirmation' => 'BrandNewSecret123!',
            ])
            ->assertOk();

        $row = $this->auditRow('user.password_reset_by_admin', $agent);

        $this->assertSame($this->admin->id, $row->actor_user_id);
        $this->assertSame($agent->id, $row->auditable_id);

        // The reset really happened...
        $this->assertTrue(Hash::check('BrandNewSecret123!', $agent->fresh()->password));

        // ...and NOTHING about the password is in the row. Serialising the
        // whole row (not just old/new_values) so a future edit that hides it
        // in some other column is caught too.
        $serialized = json_encode($row->getAttributes());
        $this->assertStringNotContainsString('BrandNewSecret123!', $serialized);
        $this->assertStringNotContainsString($agent->fresh()->password, $serialized);
        $this->assertStringNotContainsString('$2y$', $serialized);
    }

    /**
     * §4.4 — DECISION: a user changing their OWN password IS audited. See
     * UserProfileService::updatePassword()'s docblock for the reasoning (the
     * forensic case is the self-service one: session hijack -> password change
     * -> owner locked out, and ip_address is what makes the row worth having).
     *
     * Distinct action name from the Admin reset, because actor == target does
     * not distinguish them on its own — a Company Admin changing their own
     * password would look identical under a shared name.
     */
    public function test_a_self_service_password_change_is_audited_without_any_password_material(): void
    {
        $agent = User::factory()->agent()->create([
            'company_id' => $this->company->id,
            'password' => bcrypt('OldSecret123!'),
        ]);

        $this->actingAs($agent)
            ->putJson('/api/v1/me/password', [
                'current_password' => 'OldSecret123!',
                'password' => 'BrandNewSecret123!',
                'password_confirmation' => 'BrandNewSecret123!',
            ])
            ->assertOk();

        $row = $this->auditRow('user.password_changed', $agent);

        $this->assertSame($agent->id, $row->actor_user_id, 'Self-service: the actor is the owner.');
        $this->assertSame($agent->id, $row->auditable_id);
        $this->assertNotSame('user.password_reset_by_admin', $row->action);

        $serialized = json_encode($row->getAttributes());
        $this->assertStringNotContainsString('BrandNewSecret123!', $serialized);
        $this->assertStringNotContainsString('OldSecret123!', $serialized);
        $this->assertStringNotContainsString('$2y$', $serialized);
    }

    // ── BR-6 — the trail is tenant-stamped ────────────────────────────────

    /**
     * Every new row carries the TARGET's company_id, so a Company Admin's
     * audit-log view (which filters on it) shows their own tenant's rights
     * changes and no one else's.
     */
    public function test_every_new_audit_row_is_stamped_with_the_targets_company(): void
    {
        $agent = User::factory()->agent()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->admin)
            ->putJson('/api/v1/users/'.$agent->id, ['is_team_leader' => true])
            ->assertOk();
        $this->actingAs($this->admin)->deleteJson('/api/v1/users/'.$agent->id)->assertNoContent();

        $rows = AuditLog::whereIn('action', ['user.team_leader_changed', 'user.deactivated'])
            ->where('auditable_id', $agent->id)
            ->get();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame($this->company->id, $row->company_id);
        }
    }
}
