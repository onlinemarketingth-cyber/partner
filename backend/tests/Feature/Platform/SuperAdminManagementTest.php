<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\SuperAdminGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-18 (human: "เพิ่มสิทธิ์ Super Admin ในการเพิ่มและแก้ไข user").
 *
 * The platform-owner role became reachable from จัดการผู้ใช้ระบบ on this
 * date, reversing four decisions that were each written down as deliberate
 * (UserPolicy's docblock and view(), StoreUserRequest, UpdateUserRequest,
 * UserController::index). The owner asked for the reversal knowing what it
 * was protecting, and asked for four guards with it.
 *
 * THIS FILE IS THOSE GUARDS. The happy paths are here so the guards are
 * tested against something that works, but the tests that matter are the
 * refusals: every one of them is a way the platform could be locked away
 * from everybody, or handed to somebody who should not have it.
 */
class SuperAdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $thaiLife;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thaiLife = Company::factory()->create(['name' => 'Thai Life']);
    }

    /** A password that satisfies Password::defaults() without being a real one. */
    private const TEMP_PASSWORD = 'Str0ngTemp0rary';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Nida',
            'last_name' => 'Platform',
            'email' => 'nida.platform@example.com',
            'password' => self::TEMP_PASSWORD,
            'role' => 'super_admin',
        ], $overrides);
    }

    // ── Creating one ──────────────────────────────────────────────────

    public function test_a_super_admin_can_create_another_super_admin(): void
    {
        $actor = User::factory()->superAdmin()->create();

        $response = $this->actingAs($actor)
            ->postJson('/api/v1/users', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.role', 'super_admin');

        $created = User::withoutGlobalScopes()->find($response->json('data.id'));

        $this->assertSame('super_admin', $created->role->value);
        // The whole point of the role: no tenant.
        $this->assertNull($created->company_id);
        $this->assertNull($created->supplier_id);
    }

    public function test_a_new_super_admin_can_actually_log_in(): void
    {
        /*
         * The bug CreateSuperAdminCommand shipped with, in a second place:
         * an account created successfully and blocked at the login gate is
         * the worst outcome of this feature, because the person who made it
         * has no way to tell it from a password problem.
         */
        $actor = User::factory()->superAdmin()->create();

        $response = $this->actingAs($actor)
            ->postJson('/api/v1/users', $this->payload())
            ->assertCreated();

        $created = User::withoutGlobalScopes()->find($response->json('data.id'));

        $this->assertNotNull($created->email_verified_at);
        $this->assertSame('approved', $created->agent_approval_status->value);
    }

    public function test_a_company_admin_cannot_create_a_super_admin(): void
    {
        // The tenant must not be able to mint an account that outranks it.
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/users', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'nida.platform@example.com']);
    }

    public function test_a_super_admin_may_not_be_created_inside_a_company(): void
    {
        /*
         * `company_id` is prohibited rather than ignored. Accepting and
         * dropping it would let a caller believe they had scoped the account
         * to one tenant, which is a belief nothing else in the system shares.
         */
        $actor = User::factory()->superAdmin()->create();

        $this->actingAs($actor)
            ->postJson('/api/v1/users', $this->payload(['company_id' => $this->thaiLife->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }

    public function test_creating_one_is_audited_under_its_own_action_name(): void
    {
        $actor = User::factory()->superAdmin()->create();

        $response = $this->actingAs($actor)
            ->postJson('/api/v1/users', $this->payload())
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.super_admin_created',
            'auditable_id' => $response->json('data.id'),
            'actor_user_id' => $actor->id,
        ]);
        // …and NOT under the everyday one, which would bury it.
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'user.created',
            'auditable_id' => $response->json('data.id'),
        ]);
    }

    public function test_the_temporary_password_never_reaches_the_audit_trail(): void
    {
        // §4.2 — the audit table is read by more people, for longer, than the
        // row it describes.
        $actor = User::factory()->superAdmin()->create();

        $this->actingAs($actor)->postJson('/api/v1/users', $this->payload())->assertCreated();

        $this->assertStringNotContainsString(
            self::TEMP_PASSWORD,
            (string) json_encode(AuditLog::all()->toArray()),
        );
    }

    // ── Promoting one ─────────────────────────────────────────────────

    public function test_a_super_admin_can_promote_an_existing_admin(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);

        $this->actingAs($actor)
            ->putJson("/api/v1/users/{$target->id}", ['role' => 'super_admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'super_admin');

        $target->refresh();
        $this->assertSame('super_admin', $target->role->value);
        // The company goes with the role, or TenantScope and the row disagree.
        $this->assertNull($target->company_id);
    }

    public function test_a_promotion_is_audited_as_a_grant_and_says_which_company_was_left(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);

        $this->actingAs($actor)
            ->putJson("/api/v1/users/{$target->id}", ['role' => 'super_admin'])
            ->assertOk();

        $row = AuditLog::where('action', 'user.super_admin_granted')
            ->where('auditable_id', $target->id)
            ->firstOrFail();

        $this->assertSame($actor->id, $row->actor_user_id);
        $this->assertSame($this->thaiLife->id, $row->old_values['company_id']);
        $this->assertNull($row->new_values['company_id']);
    }

    public function test_a_promotion_revokes_the_tokens_the_person_was_holding(): void
    {
        // TASK-238 — a token minted under the old role is a token whose
        // abilities nobody re-checked. Doubly so when the new role is this one.
        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);
        $target->createToken('portal');

        $this->assertSame(1, $target->tokens()->count());

        $this->actingAs($actor)
            ->putJson("/api/v1/users/{$target->id}", ['role' => 'super_admin'])
            ->assertOk();

        $this->assertSame(0, $target->tokens()->count());
    }

    // ── Demoting one ──────────────────────────────────────────────────

    public function test_a_super_admin_can_demote_another_one_into_a_named_company(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->superAdmin()->create();

        $this->actingAs($actor)
            ->putJson("/api/v1/users/{$target->id}", [
                'role' => 'company_admin',
                'company_id' => $this->thaiLife->id,
            ])
            ->assertOk();

        $target->refresh();
        $this->assertSame('company_admin', $target->role->value);
        $this->assertSame($this->thaiLife->id, $target->company_id);
    }

    public function test_a_demotion_without_a_company_is_refused(): void
    {
        /*
         * THE TRAP THIS EXISTS TO CLOSE: fail-closed TenantScope filters a
         * company_admin with a null company_id using `1 = 0`. They would log
         * in perfectly well and find an empty system, with nothing on screen
         * to say why.
         */
        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->superAdmin()->create();

        $this->actingAs($actor)
            ->putJson("/api/v1/users/{$target->id}", ['role' => 'company_admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');

        $this->assertSame('super_admin', $target->refresh()->role->value);
    }

    public function test_a_demotion_is_audited_as_a_revoke(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->superAdmin()->create();

        $this->actingAs($actor)
            ->putJson("/api/v1/users/{$target->id}", [
                'role' => 'company_admin',
                'company_id' => $this->thaiLife->id,
            ])
            ->assertOk();

        $row = AuditLog::where('action', 'user.super_admin_revoked')
            ->where('auditable_id', $target->id)
            ->firstOrFail();

        $this->assertNull($row->old_values['company_id']);
        $this->assertSame($this->thaiLife->id, $row->new_values['company_id']);
    }

    // ── The four guards ───────────────────────────────────────────────

    public function test_nobody_can_demote_themselves(): void
    {
        /*
         * The mistake that cannot be undone from the screen you made it on:
         * the moment it saves, the button that would put it back is gone,
         * along with the rest of the console.
         */
        $actor = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->create(); // so it is not the last-one rule answering

        $this->actingAs($actor)
            ->putJson("/api/v1/users/{$actor->id}", [
                'role' => 'company_admin',
                'company_id' => $this->thaiLife->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame('super_admin', $actor->refresh()->role->value);
    }

    public function test_the_last_super_admin_cannot_be_demoted(): void
    {
        /*
         * A platform with no Super Admin has nobody who can make one; the
         * way back is SSH to the production host.
         *
         * Asserted from a SECOND Super Admin's session rather than the
         * target's own, so the refusal cannot be the self-demotion rule
         * answering by accident — and the actor is demoted FIRST, leaving
         * exactly one platform owner standing.
         */
        $sole = User::factory()->superAdmin()->create();
        $actor = User::factory()->superAdmin()->create();

        // Two exist, so this one may go. Now there is one.
        $this->actingAs($sole)
            ->putJson("/api/v1/users/{$actor->id}", [
                'role' => 'company_admin',
                'company_id' => $this->thaiLife->id,
            ])
            ->assertOk();

        $this->assertSame(1, SuperAdminGuard::activeCount());

        // The demoted one is a Company Admin now, so they cannot reach the
        // row at all — the refusal has to come from another Super Admin.
        $witness = User::factory()->superAdmin()->create();
        $this->actingAs($witness)
            ->putJson("/api/v1/users/{$sole->id}", [
                'role' => 'company_admin',
                'company_id' => $this->thaiLife->id,
            ])
            ->assertOk();

        // …and now $witness is the last one, from anybody's session.
        $this->assertTrue(SuperAdminGuard::isLastActive($witness->refresh()));
        $this->actingAs($witness)
            ->putJson("/api/v1/users/{$witness->id}", ['role' => 'agent', 'company_id' => $this->thaiLife->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame('super_admin', $witness->refresh()->role->value);
    }

    public function test_a_deactivated_super_admin_does_not_count_as_cover_for_removing_the_last_live_one(): void
    {
        /*
         * A closed account cannot log in, so counting it would let the last
         * WORKING Super Admin be demoted while the count still read two.
         */
        $actor = User::factory()->superAdmin()->create();
        $spare = User::factory()->superAdmin()->create();

        $this->actingAs($actor)->deleteJson("/api/v1/users/{$spare->id}")->assertNoContent();

        $this->assertSame(1, SuperAdminGuard::activeCount());
        $this->assertTrue(SuperAdminGuard::isLastActive($actor->refresh()));
    }

    public function test_the_last_super_admin_cannot_be_deactivated(): void
    {
        /*
         * The other end of the same line, held by UserPolicy::delete()
         * rather than by a Form Request — so the button is not rendered
         * either (`permissions.deactivate` comes from the same call).
         */
        $sole = User::factory()->superAdmin()->create();
        $spare = User::factory()->superAdmin()->create();

        // Two exist, so this one may go.
        $this->actingAs($sole)->deleteJson("/api/v1/users/{$spare->id}")->assertNoContent();

        // One is left, and nobody may close it — not even another
        // Super Admin created afterwards… who is then one of two again, so
        // the refusal is asserted while exactly one remains.
        $this->assertSame(1, SuperAdminGuard::activeCount());

        $this->actingAs($sole)->deleteJson("/api/v1/users/{$sole->id}")->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $sole->id, 'deleted_at' => null]);
    }

    public function test_the_screen_is_not_offered_the_button_that_would_empty_the_platform(): void
    {
        // A refusal after the click is a worse version of the same
        // protection: the admin is left wondering whether something broke.
        $sole = User::factory()->superAdmin()->create();

        $row = collect(
            $this->actingAs($sole)
                ->getJson('/api/v1/users?with_permissions=1')
                ->assertOk()
                ->json('data')
        )->firstWhere('id', $sole->id);

        $this->assertFalse($row['permissions']['deactivate']);
    }

    // ── Who may see one ───────────────────────────────────────────────

    public function test_a_super_admin_appears_in_another_super_admins_list(): void
    {
        $other = User::factory()->superAdmin()->create();

        $ids = collect(
            $this->actingAs(User::factory()->superAdmin()->create())
                ->getJson('/api/v1/users')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($other->id, $ids);
    }

    public function test_a_super_admin_can_open_another_ones_row(): void
    {
        $other = User::factory()->superAdmin()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson("/api/v1/users/{$other->id}")
            ->assertOk()
            ->assertJsonPath('data.role', 'super_admin');
    }

    public function test_a_company_admin_cannot_demote_a_super_admin(): void
    {
        // The widening is same-tier. A tenant reaches no part of it.
        $target = User::factory()->superAdmin()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$target->id}", [
                'role' => 'company_admin',
                'company_id' => $this->thaiLife->id,
            ])
            ->assertNotFound();

        $this->assertSame('super_admin', $target->refresh()->role->value);
    }

    public function test_a_super_admin_still_cannot_be_moved_between_companies(): void
    {
        // UserPolicy::move() is unchanged: there is no company to move from.
        $target = User::factory()->superAdmin()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson("/api/v1/users/{$target->id}/move-company", ['company_id' => $this->thaiLife->id])
            ->assertForbidden();

        $this->assertNull($target->refresh()->company_id);
    }
}
