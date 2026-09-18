<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-259 — what "จัดการผู้ใช้ระบบ" needs from /users, and what it must
 * never be able to do.
 *
 * The screen this feeds renders a row per user with buttons on it. Two
 * things about that are dangerous and both are asserted here:
 *
 *   1. THE BUTTONS COME FROM THE SERVER. A screen that re-derives "super
 *      admin, or same company, and never yourself" is a screen that will
 *      one day offer a button the API then refuses — or, worse, hide one
 *      from somebody who was allowed. `permissions` is the Policy's own
 *      answer, asked per row.
 *   2. THE LIST IS STILL THE LIST. Adding a role filter and a last-login
 *      column must not widen who appears in it: BR-6 for a Company Admin,
 *      and no Super Admin rows for anybody (UserPolicy::view).
 */
class UserManagementScreenTest extends TestCase
{
    use RefreshDatabase;

    private Company $thaiLife;

    private Company $aia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thaiLife = Company::factory()->create();
        $this->aia = Company::factory()->create();
    }

    /**
     * 2026-09-18 — one person's row out of a list response, BY ID.
     *
     * Several tests here used to read `data.0` and mean "the only agent",
     * which held only while the list could not contain a Super Admin. It can
     * now, and they sort first (UserController::index), so `data.0` in a
     * Super Admin's response is the acting Super Admin. Positional reads on
     * this endpoint are a trap; this helper is the way out of it.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowFor(array $rows, int $id): array
    {
        $row = collect($rows)->firstWhere('id', $id);

        $this->assertNotNull($row, "Expected user #{$id} in the response, and it is not there.");

        return $row;
    }

    private function loginRow(User $user, string $when): void
    {
        AuditLog::create([
            'company_id' => $user->company_id,
            'actor_user_id' => $user->id,
            'action' => 'auth.login',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => '127.0.0.1',
        ]);

        AuditLog::where('actor_user_id', $user->id)->latest('id')->first()
            ->forceFill(['created_at' => $when])->save();
    }

    // ── The role filter ───────────────────────────────────────────────

    public function test_the_admins_can_be_asked_for_by_themselves(): void
    {
        /*
         * "ตอนนี้ใครเป็นแอดมินบ้าง" used to mean loading every agent and
         * filtering in the browser — which this endpoint's pagination makes
         * a lie: page 1 of everybody is not page 1 of the admins.
         */
        User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);
        User::factory()->count(3)->agent()->create(['company_id' => $this->thaiLife->id]);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/users?role=company_admin')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('company_admin', $response->json('data.0.role'));
    }

    /*
     * 2026-09-18 — this pair replaces one test ("asking for super admins
     * returns nobody rather than everybody"), which asserted zero rows for
     * EVERY caller. Half of it still holds and is the half that protects
     * anything; the other half is now the feature.
     */
    public function test_a_company_admin_asking_for_super_admins_gets_nobody_rather_than_everybody(): void
    {
        /*
         * The filter must still fail CLOSED for a tenant. `role=super_admin`
         * reaching a query that ignores unknown values would hand back the
         * whole list, and the caller would read it as "these are the
         * platform admins".
         */
        User::factory()->superAdmin()->create();
        User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $response = $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]))
            ->getJson('/api/v1/users?role=super_admin')
            ->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    public function test_a_super_admin_asking_for_super_admins_gets_them(): void
    {
        // The screen's บทบาท filter has to be able to answer "who owns this
        // platform?" — the question a Super-Admin-only list exists for.
        $other = User::factory()->superAdmin()->create();
        User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/users?role=super_admin')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertContains($other->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_the_company_scope_in_the_header_does_not_hide_the_platform_owners(): void
    {
        /*
         * A Super Admin carries no company_id, so a plain `where company_id
         * = ?` drops every one of them the moment a company is picked in the
         * header — and an empty Super Admin section reads as "there are
         * none" on the one screen that accounts for them.
         */
        $other = User::factory()->superAdmin()->create();
        $agent = User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);
        $elsewhere = User::factory()->agent()->create(['company_id' => $this->aia->id]);

        $ids = collect(
            $this->actingAs(User::factory()->superAdmin()->create())
                ->getJson("/api/v1/users?company_id={$this->thaiLife->id}")
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($other->id, $ids);
        $this->assertContains($agent->id, $ids);
        // …and the scope still narrows: the other company's agent is gone.
        $this->assertNotContains($elsewhere->id, $ids);
    }

    public function test_a_company_admin_still_only_sees_their_own_company(): void
    {
        // BR-6. A new filter is exactly when a scope quietly stops applying.
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);
        User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/users?role=company_admin')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($admin->id, $response->json('data.0.id'));
    }

    // ── Last login ────────────────────────────────────────────────────

    public function test_the_list_can_say_when_each_person_last_signed_in(): void
    {
        $agent = User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);
        $this->loginRow($agent, '2026-08-01 09:00:00');
        $this->loginRow($agent, '2026-09-01 09:00:00');

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/users?with_last_login=1')
            ->assertOk();

        // The LATEST login, not the first one and not a count.
        // 2026-09-18 — looked up by id, not `data.0`: the acting Super Admin
        // is now in their own list, and sorts first.
        $this->assertStringContainsString('2026-09-01', $this->rowFor($response->json('data'), $agent->id)['last_login_at']);
    }

    public function test_somebody_who_has_never_signed_in_reads_as_null_not_as_a_date(): void
    {
        /*
         * Null here means "no login recorded since auditing began", and the
         * screen has to say that in words. Any invented date — the account's
         * creation, the epoch — would be read as a fact about a person.
         */
        $agent = User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/users?with_last_login=1')
            ->assertOk();

        $this->assertNull($this->rowFor($response->json('data'), $agent->id)['last_login_at']);
    }

    public function test_another_persons_login_is_not_mistaken_for_this_ones(): void
    {
        // The subselect correlates on actor_user_id. A missing correlation
        // would give every row the same, most recent, login in the system —
        // which looks completely plausible on screen.
        $quiet = User::factory()->agent()->create(['company_id' => $this->thaiLife->id, 'name' => 'A Quiet']);
        $busy = User::factory()->agent()->create(['company_id' => $this->thaiLife->id, 'name' => 'B Busy']);
        $this->loginRow($busy, '2026-09-01 09:00:00');

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/users?with_last_login=1')
            ->assertOk();

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertNull($rows[$quiet->id]['last_login_at']);
        $this->assertNotNull($rows[$busy->id]['last_login_at']);
    }

    public function test_the_field_is_absent_for_callers_that_did_not_ask(): void
    {
        // Four other screens read /users. A key that is always null there is
        // a field somebody eventually renders as "never logged in".
        $agent = User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/users')
            ->assertOk();

        $this->assertArrayNotHasKey('last_login_at', $this->rowFor($response->json('data'), $agent->id));
    }

    // ── Per-row permissions ───────────────────────────────────────────

    public function test_a_super_admin_is_told_they_may_do_everything_to_an_agent(): void
    {
        $agent = User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/users?with_permissions=1')
            ->assertOk();

        /*
         * assertSame on the WHOLE array, deliberately: a screen decides what to
         * draw from these keys, so a key silently disappearing is a control
         * silently vanishing for everybody. That is also why adding one has to
         * come through here — 2026-09-08 added the two registration decisions
         * when the agent roster started offering อนุมัติ / ไม่อนุมัติ on its own
         * rows.
         */
        $this->assertSame([
            'update' => true,
            'deactivate' => true,
            'restore' => true,
            'move_company' => true,
            'approve_registration' => true,
            'reject_registration' => true,
        ], $this->rowFor($response->json('data'), $agent->id)['permissions']);
    }

    public function test_a_company_admin_is_not_offered_the_move_button(): void
    {
        // UserPolicy::move() is Super-Admin-only because moving a user
        // re-points tenant isolation for every future query about them.
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);
        User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/users?with_permissions=1')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('role', 'agent');
        $this->assertTrue($row['permissions']['update']);
        $this->assertFalse($row['permissions']['move_company']);
    }

    public function test_nobody_is_offered_the_button_that_would_lock_them_out(): void
    {
        /*
         * UserPolicy::delete() refuses self-deactivation. The screen must not
         * render that button at all: a refusal after the click is a worse
         * version of the same protection, and the admin has to wonder whether
         * something is broken.
         */
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/users?with_permissions=1')
            ->assertOk();

        $self = collect($response->json('data'))->firstWhere('id', $admin->id);
        $this->assertFalse($self['permissions']['deactivate']);
        // …but they can still edit their own row.
        $this->assertTrue($self['permissions']['update']);
    }

    public function test_permissions_are_absent_unless_asked_for(): void
    {
        // A Gate call per row per key is not free, and no other caller of
        // /users renders per-row actions.
        User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/users')
            ->assertOk();

        $this->assertArrayNotHasKey('permissions', $response->json('data.0'));
    }

    // ── The actions themselves still enforce, server-side ─────────────

    public function test_a_company_admin_cannot_touch_another_companys_user_even_by_asking_directly(): void
    {
        /*
         * The screen only ever shows what it was told. This is the check that
         * makes that safe: the API refuses regardless of what any client
         * rendered.
         *
         * 404, NOT 403, and that is the stronger answer: TenantScope makes
         * the row invisible to route-model binding, so the refusal does not
         * confirm that this user id exists at all (§5 rule 5). A 403 here
         * would be a working cross-tenant existence oracle.
         */
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);
        $stranger = User::factory()->agent()->create(['company_id' => $this->aia->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$stranger->id}", ['role' => 'company_admin'])
            ->assertNotFound();

        $this->assertSame('agent', $stranger->refresh()->role->value);
    }

    public function test_an_agent_can_be_promoted_to_company_admin(): void
    {
        /*
         * The gap this screen closes. UpdateUserRequest has allowed this
         * since it was written; the only admin form that could reach it hid
         * the option (TASK-130), so in practice promoting somebody meant
         * editing the database by hand.
         */
        $agent = User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/users/{$agent->id}", ['role' => 'company_admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'company_admin');

        // TASK-238 — and the promotion takes their old sessions with it.
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.role_changed', 'auditable_id' => $agent->id]);
    }

    public function test_a_company_admin_cannot_promote_anybody_to_super_admin_through_this_screen(): void
    {
        /*
         * 2026-09-18 — this was "nobody can promote anybody to super admin
         * through this screen ... the one role that has no UI path in either
         * direction, by design: it is created from the command line and
         * audited there". A SUPER ADMIN can now (SuperAdminManagementTest
         * covers it); a tenant still cannot, which is the half of that rule
         * worth keeping — a company must not be able to mint an account that
         * outranks the company.
         */
        $agent = User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]))
            ->putJson("/api/v1/users/{$agent->id}", ['role' => 'super_admin'])
            ->assertUnprocessable();

        $this->assertSame('agent', $agent->refresh()->role->value);
    }
}
