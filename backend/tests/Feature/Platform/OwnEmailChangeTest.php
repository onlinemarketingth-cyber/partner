<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-247 — PUT /me/email: the owner changes the address they sign in with.
 *
 * Every other field on the profile screen was self-service already; this one
 * was not, so correcting a typo in your own login address meant asking an
 * admin to do it — on a screen that, until TASK-246, could not do it either.
 *
 * The whole security design of the endpoint is the current password. An email
 * is the identifier the account authenticates as, so changing it is the first
 * half of a takeover: a stolen session cookie must not be enough to point an
 * account at an address the attacker controls. That is the same rule the
 * self-service password change applies, and together they mean neither
 * credential can be replaced by a session alone.
 *
 * The second half is the audit row, which is the ONLY place the old address
 * survives — the users row has forgotten it by the time anybody thinks to ask.
 */
class OwnEmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ngPassword';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->companyAdmin()->create([
            'company_id' => Company::factory()->create()->id,
            'email' => 'old@example.com',
            'password' => self::PASSWORD,
        ]);
    }

    public function test_the_owner_changes_their_own_login_address(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/email', [
                'current_password' => self::PASSWORD,
                'email' => 'new@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'new@example.com');

        $this->assertSame('new@example.com', $this->user->refresh()->email);
    }

    public function test_a_session_alone_cannot_change_it(): void
    {
        /*
         * The takeover this endpoint is built against: somebody holding a
         * stolen cookie points the account at their own address. They are
         * stopped by having to know the current password — the thing the
         * cookie does not carry.
         */
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/email', [
                'current_password' => 'not-the-password',
                'email' => 'attacker@example.com',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertSame('old@example.com', $this->user->refresh()->email);
    }

    public function test_the_current_password_is_not_optional(): void
    {
        // Omitting the field must fail the same way as getting it wrong;
        // "required" and "current_password" are two separate rules and only
        // one of them was ever going to be forgotten.
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/email', ['email' => 'new@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
    }

    public function test_an_address_another_account_already_uses_is_refused(): void
    {
        User::factory()->agent()->create([
            'company_id' => $this->user->company_id,
            'email' => 'taken@example.com',
        ]);

        $this->actingAs($this->user)
            ->putJson('/api/v1/me/email', [
                'current_password' => self::PASSWORD,
                'email' => 'taken@example.com',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_resubmitting_your_own_address_is_a_harmless_no_op(): void
    {
        // Uniqueness ignores this user, so their own address is not "already
        // taken" — and nothing happened, so nothing is recorded.
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/email', [
                'current_password' => self::PASSWORD,
                'email' => 'old@example.com',
            ])
            ->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'user.email_changed')->count());
    }

    public function test_the_change_is_recorded_with_the_address_it_replaced(): void
    {
        /*
         * The row that answers "when did this account stop belonging to the
         * person whose name is on it". The OLD address exists nowhere else
         * once the update commits.
         */
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/email', [
                'current_password' => self::PASSWORD,
                'email' => 'new@example.com',
            ])
            ->assertOk();

        $row = AuditLog::where('action', 'user.email_changed')->firstOrFail();

        $this->assertSame($this->user->id, $row->actor_user_id);
        $this->assertSame($this->user->id, $row->auditable_id);
        $this->assertSame('old@example.com', $row->old_values['email']);
        $this->assertSame('new@example.com', $row->new_values['email']);
    }

    public function test_it_does_not_sign_the_owner_out_of_their_other_devices(): void
    {
        /*
         * Deliberately UNLIKE a password change, which ends every other
         * session because the credential it replaced may be in someone else's
         * hands. Nothing was invalidated here: every existing session is
         * exactly as trustworthy as it was a second ago, and signing somebody
         * out for correcting a typo would be a surprise with no security to
         * show for it. The takeover path is covered where it actually
         * happens — an attacker needs the password to get this far, and if
         * they then change it, THAT is what revokes the owner's sessions.
         */
        $this->user->createToken('phone');
        $this->user->createToken('tablet');

        $this->actingAs($this->user)
            ->putJson('/api/v1/me/email', [
                'current_password' => self::PASSWORD,
                'email' => 'new@example.com',
            ])
            ->assertOk();

        $this->assertSame(2, $this->user->tokens()->count());
    }

    public function test_the_new_address_is_the_one_that_signs_in(): void
    {
        // The point of the whole endpoint, asserted end to end rather than
        // assumed from the column having changed.
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/email', [
                'current_password' => self::PASSWORD,
                'email' => 'new@example.com',
            ])
            ->assertOk();

        $this->post('/api/v1/logout');

        config(['sanctum.stateful' => ['agent.localhost']]);

        $this->withHeader('Origin', 'http://agent.localhost')
            ->postJson('/api/v1/login', ['email' => 'new@example.com', 'password' => self::PASSWORD])
            ->assertOk();
    }

    public function test_it_requires_authentication(): void
    {
        $this->putJson('/api/v1/me/email', [
            'current_password' => self::PASSWORD,
            'email' => 'new@example.com',
        ])->assertUnauthorized();
    }
}
