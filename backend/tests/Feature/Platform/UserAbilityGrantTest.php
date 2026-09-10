<?php

namespace Tests\Feature\Platform;

use App\Enums\Ability;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Models\UserAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-10 (human: "การกระจายสิทธิ์ให้ Company Admin และ Admin ที่ได้สิทธิ์
 * ในการตัดได้เฉพาะหน้าการตัดสิทธิ์ เพราะทำงานคนละหน้าที่กัน").
 *
 * Redeeming a voucher consumes a customer's paid entitlement at a counter.
 * Until today every Company Admin could do it by virtue of being one — a
 * permission nobody chose to give and nobody could take away.
 *
 * Two things now grant it, and both are deliberate acts: the front-desk role
 * whose whole job it is, and a per-person grant for a Company Admin who also
 * works the counter. This file is the second one, plus the boundary that
 * stops it becoming a way to hand out anything else.
 */
class UserAbilityGrantTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);
        $this->staff = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);
    }

    private function grantedTo(User $user): array
    {
        // pluck() on an Eloquent builder applies the model's casts, so this
        // comes back as Ability instances — compared as strings everywhere
        // because that is what the API and the request both speak.
        return $user->abilityGrants()
            ->pluck('ability')
            ->map(fn (Ability $ability) => $ability->value)
            ->all();
    }

    // ── Giving and taking away ───────────────────────────────────────

    public function test_an_admin_can_give_a_colleague_the_redemption_right(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/v1/users/{$this->staff->id}/abilities", [
                'abilities' => [Ability::VoucherRedeem->value],
            ])
            ->assertOk()
            ->assertJsonPath('data.granted_abilities', [Ability::VoucherRedeem->value]);

        $this->assertSame([Ability::VoucherRedeem->value], $this->grantedTo($this->staff));
    }

    public function test_an_empty_list_takes_it_back(): void
    {
        /*
         * The request carries the WHOLE set, so revoking is sending fewer —
         * `present` rather than `required` on the field, because `required`
         * rejects an empty array and would make "revoke everything" the one
         * thing the screen could not express.
         */
        UserAbility::create(['user_id' => $this->staff->id, 'ability' => Ability::VoucherRedeem->value]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/users/{$this->staff->id}/abilities", ['abilities' => []])
            ->assertOk();

        $this->assertSame([], $this->grantedTo($this->staff));
    }

    public function test_the_grant_changes_what_the_person_can_actually_do(): void
    {
        // The point of all of it — asserted through the endpoint the grant
        // exists to open, not through the resolver in isolation.
        $this->actingAs($this->staff)
            ->postJson('/api/v1/vouchers/redeem', ['code' => 'does-not-matter'])
            ->assertForbidden();

        $this->actingAs($this->admin)->putJson("/api/v1/users/{$this->staff->id}/abilities", [
            'abilities' => [Ability::VoucherRedeem->value],
        ])->assertOk();

        $this->actingAs($this->staff->fresh())
            ->postJson('/api/v1/vouchers/redeem', ['code' => 'does-not-matter'])
            ->assertUnprocessable();
    }

    public function test_who_granted_it_is_recorded_on_the_row_and_in_the_audit_log(): void
    {
        /*
         * "Who let them do that" is asked months later, usually after
         * something went wrong. The row says who granted it; the audit log
         * says when and what changed — both, because the row survives only
         * while the grant does and a revocation would otherwise leave no
         * trace at all.
         */
        $this->actingAs($this->admin)->putJson("/api/v1/users/{$this->staff->id}/abilities", [
            'abilities' => [Ability::VoucherRedeem->value],
        ])->assertOk();

        $this->assertDatabaseHas('user_abilities', [
            'user_id' => $this->staff->id,
            'ability' => Ability::VoucherRedeem->value,
            'granted_by_user_id' => $this->admin->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.abilities_updated',
            'auditable_id' => $this->staff->id,
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_an_unchanged_set_writes_no_audit_row(): void
    {
        // Saving a form without changing it is not an event. An audit trail
        // full of no-ops is one nobody reads.
        UserAbility::create(['user_id' => $this->staff->id, 'ability' => Ability::VoucherRedeem->value]);

        $this->actingAs($this->admin)->putJson("/api/v1/users/{$this->staff->id}/abilities", [
            'abilities' => [Ability::VoucherRedeem->value],
        ])->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'user.abilities_updated')->count());
    }

    // ── The boundary ─────────────────────────────────────────────────

    public function test_only_the_abilities_a_screen_may_hand_out_are_accepted(): void
    {
        /*
         * The reason the grantable set is a constant rather than "any
         * Ability": ADR-027 withholds the payment-gateway setting from a
         * Company Admin precisely because it names the bank account their
         * company's revenue lands in. A general endpoint here would be a way
         * to grant it to themselves.
         */
        $this->actingAs($this->admin)
            ->putJson("/api/v1/users/{$this->staff->id}/abilities", [
                'abilities' => [Ability::SettingsPaymentGatewayUpdate->value],
            ])
            ->assertJsonValidationErrors('abilities.0');

        $this->assertSame([], $this->grantedTo($this->staff));
    }

    public function test_an_admin_cannot_grant_anything_to_another_companys_user(): void
    {
        // BR-6. The Policy that guards every other write to this user guards
        // this one — `authorize('update', $user)`, not a second rule.
        $outsider = User::factory()->companyAdmin()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/users/{$outsider->id}/abilities", [
                'abilities' => [Ability::VoucherRedeem->value],
            ])
            ->assertNotFound();
    }

    public function test_an_agent_cannot_grant_anything_to_anyone(): void
    {
        $agent = User::factory()->agent()->create(['company_id' => $this->company->id]);

        $this->actingAs($agent)
            ->putJson("/api/v1/users/{$this->staff->id}/abilities", [
                'abilities' => [Ability::VoucherRedeem->value],
            ])
            ->assertForbidden();
    }

    // ── The front-desk role's wall ───────────────────────────────────

    public function test_front_desk_staff_reach_the_redemption_endpoints_and_nothing_else(): void
    {
        /*
         * An ALLOWLIST at the door, not a hundred Policy edits. Most admin
         * gates ask isCompanyAdmin() and refuse a new role for free — but
         * OrderPolicy::viewAny() returns true for any authenticated user, and
         * whether the NEXT one of those leaks depends on somebody remembering
         * this role exists while writing it.
         */
        $frontDesk = User::factory()->create([
            'company_id' => $this->company->id,
            'role' => UserRole::VoucherStaff,
        ]);

        // Their job: past the wall, into the endpoint's own validation.
        $this->actingAs($frontDesk)
            ->postJson('/api/v1/vouchers/redeem', ['code' => 'does-not-matter'])
            ->assertUnprocessable();

        // Everything else in the company, refused by the wall itself.
        foreach (['/api/v1/orders', '/api/v1/clients', '/api/v1/users', '/api/v1/products'] as $path) {
            $this->actingAs($frontDesk)->getJson($path)->assertForbidden();
        }
    }

    public function test_front_desk_staff_can_still_see_who_they_are_and_log_out(): void
    {
        // A wall that blocked /me would leave the console unable to render at
        // all, which reads as a broken app rather than a limited account.
        $frontDesk = User::factory()->create([
            'company_id' => $this->company->id,
            'role' => UserRole::VoucherStaff,
            'password' => bcrypt('password123'),
        ]);

        $this->actingAs($frontDesk)->getJson('/api/v1/me')->assertOk();

        /*
         * Logout destroys a session, so it needs a real one — actingAs() never
         * mints a session store and the request would 500 before reaching the
         * wall, proving nothing. Logging in for real is the same shape
         * CompanyDeactivationTest uses for its own logout test, and it also
         * pins that this role can log in at all.
         */
        config(['sanctum.stateful' => ['agent.localhost']]);

        $this->withHeader('Origin', 'http://agent.localhost')
            ->postJson('/api/v1/login', ['email' => $frontDesk->email, 'password' => 'password123'])
            ->assertOk();

        $this->withHeader('Origin', 'http://agent.localhost')
            ->postJson('/api/v1/logout')
            ->assertSuccessful();
    }

    public function test_the_wall_does_not_touch_anybody_else(): void
    {
        // The most likely way this middleware goes wrong: a path-matching
        // slip that starts refusing ordinary admins.
        $this->actingAs($this->admin)->getJson('/api/v1/orders')->assertOk();
    }
}
