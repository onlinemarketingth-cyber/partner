<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-238 — when somebody's rights change, their tokens stop working NOW.
 *
 * ── THE GAP (2026-09-05) ──
 *
 * The agent portal authenticates with a bearer token that lives twelve
 * hours. Deactivating an account, changing its role, resetting its password
 * or moving it to another company all wrote the new state and touched
 * nothing else — so the person who had just been locked out carried a
 * working token into a system that moves money, for up to half a day.
 * "Deactivated" has to mean deactivated now.
 *
 * ── HOW THESE TESTS ASSERT IT ──
 *
 * By USING the token afterwards, never by counting rows. A count passes
 * against an implementation that deletes the row while some cache still
 * honours it; a request that comes back 401 is the actual guarantee.
 */
class TokenRevocationOnRightsChangeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $agent;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->agent = User::factory()->agent()->create(['company_id' => $this->company->id]);
        $this->admin = User::factory()->superAdmin()->create();

        // The same shape AuthController mints for the agent portal.
        $this->token = $this->agent->createToken('agent-portal', ['*'], now()->addHours(12))->plainTextToken;
    }

    /**
     * Forget whoever actingAs() authenticated a moment ago.
     *
     * Without this the admin who just performed the action is still the
     * authenticated user for the rest of the test, so the next request
     * answers as THEM and the token under test is never consulted — every
     * assertion below would pass while proving nothing.
     */
    private function forgetTheActingAdmin(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** The token still works — the control every test below is measured against. */
    private function assertTokenWorks(): void
    {
        $this->forgetTheActingAdmin();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Auth-Mode', 'token')
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    private function assertTokenIsDead(): void
    {
        $this->forgetTheActingAdmin();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Auth-Mode', 'token')
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_the_token_works_before_anything_changes(): void
    {
        // Without this, every assertion below could pass because the token
        // was never valid in the first place.
        $this->assertTokenWorks();
    }

    public function test_deactivating_an_account_kills_its_tokens_immediately(): void
    {
        $this->assertTokenWorks();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/users/{$this->agent->id}")
            ->assertNoContent();

        $this->assertTokenIsDead();
    }

    public function test_an_admin_resetting_a_password_kills_the_old_tokens(): void
    {
        // The admin is resetting it BECAUSE something is wrong. Leaving the
        // sessions the old password opened would defeat the reset.
        $this->assertTokenWorks();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/users/{$this->agent->id}/reset-password", [
                'password' => 'correct horse 8',
            ])
            ->assertOk();

        $this->assertTokenIsDead();
    }

    public function test_changing_a_role_kills_the_tokens_minted_under_the_old_one(): void
    {
        $this->assertTokenWorks();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/users/{$this->agent->id}", ['role' => 'company_admin'])
            ->assertOk();

        $this->assertTokenIsDead();
    }

    public function test_moving_a_user_to_another_company_kills_their_tokens(): void
    {
        // Crossing a company boundary is the biggest scope change there is
        // (BR-6): the token was issued to somebody who belonged elsewhere.
        $this->assertTokenWorks();
        $elsewhere = Company::factory()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/users/{$this->agent->id}/move-company", [
                'company_id' => $elsewhere->id,
            ])
            ->assertOk();

        $this->assertTokenIsDead();
    }

    public function test_a_reporting_line_edit_does_NOT_log_anybody_out(): void
    {
        /*
         * The other side of the rule. is_team_leader and manager_id change
         * what a person SEES within rights they already hold; logging them
         * out for an org-chart tidy-up is noise, and noise is how a security
         * measure gets switched off.
         */
        $leader = User::factory()->agent()->create(['company_id' => $this->company->id]);
        $this->assertTokenWorks();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/users/{$this->agent->id}", ['manager_id' => $leader->id])
            ->assertOk();

        $this->assertTokenWorks();
    }

    public function test_changing_your_own_password_ends_every_OTHER_session(): void
    {
        /*
         * What "change my password" is expected to mean. A second token —
         * the one on the device somebody is worried about — must stop
         * working, while the browser doing the changing stays logged in.
         * Logging people out of the device they are standing at, as a reward
         * for improving their own security, is how they learn not to bother.
         */
        $otherDevice = $this->token;
        $thisDevice = $this->agent->createToken('agent-portal', ['*'], now()->addHours(12))->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$thisDevice)
            ->withHeader('X-Auth-Mode', 'token')
            ->putJson('/api/v1/me/password', [
                'current_password' => 'password',
                'password' => 'correct horse 8',
                'password_confirmation' => 'correct horse 8',
            ])
            ->assertOk();

        $this->forgetTheActingAdmin();
        $this->withHeader('Authorization', 'Bearer '.$otherDevice)
            ->withHeader('X-Auth-Mode', 'token')
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        $this->forgetTheActingAdmin();
        $this->withHeader('Authorization', 'Bearer '.$thisDevice)
            ->withHeader('X-Auth-Mode', 'token')
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    public function test_the_revocation_says_why_it_happened(): void
    {
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/users/{$this->agent->id}")
            ->assertNoContent();

        $row = AuditLog::where('action', 'user.api_tokens_revoked')->firstOrFail();

        // A revocation with no cause is the kind of entry that makes a
        // reviewer suspect a breach months later.
        $this->assertSame('account deactivated', $row->new_values['reason']);
        $this->assertSame(1, $row->new_values['revoked_count']);
    }

    public function test_nothing_is_written_when_there_was_no_token_to_revoke(): void
    {
        // An audit trail that records non-events is one people stop reading.
        $quiet = User::factory()->agent()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/users/{$quiet->id}")
            ->assertNoContent();

        $this->assertSame(
            0,
            AuditLog::where('action', 'user.api_tokens_revoked')->where('auditable_id', $quiet->id)->count(),
        );
    }
}
