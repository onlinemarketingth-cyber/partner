<?php

namespace Tests\Feature\Auth;

use App\Enums\AgentApprovalStatus;
use App\Models\Company;
use App\Models\CompanyInviteCode;
use App\Models\User;
use App\Notifications\VerifyRegistrationEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TASK-115 (implements TASK-021) / ADR-025 §8 — the login gate.
 *
 * Before this, `agent_approval_status` gated nothing and a pending,
 * unverified self-registrant could log in and work normally. Every test here
 * pins one half of closing that hole; the regression tests at the bottom pin
 * the half that must NOT change.
 */
class LoginGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * POST /api/v1/login the way the SPA actually does it.
     *
     * The Origin header is not decoration: Sanctum's
     * EnsureFrontendRequestsAreStateful only starts a session when the origin
     * matches config('sanctum.stateful'), and without a session
     * Auth::attempt() cannot run at all. Copied verbatim from
     * LoginRememberMeTest, including pinning the stateful list rather than
     * trusting a developer's local .env.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postLogin(array $payload): TestResponse
    {
        config(['sanctum.stateful' => ['agent.localhost']]);

        return $this->withHeader('Origin', 'http://agent.localhost')
            ->postJson('/api/v1/login', $payload);
    }

    /**
     * A self-registered agent: pending, unverified, and carrying the
     * invite-code attribution that makes User::isSelfRegistered() true. This
     * is exactly the row RegistrationService::registerViaEmail() produces.
     */
    private function selfRegisteredAgent(Company $company, array $overrides = []): User
    {
        $code = CompanyInviteCode::factory()->create(['company_id' => $company->id]);

        return User::factory()->agent()->create(array_merge([
            'company_id' => $company->id,
            'password' => bcrypt('password123'),
            'email_verified_at' => null,
            'agent_approval_status' => AgentApprovalStatus::Pending,
            'registered_via_invite_code_id' => $code->id,
        ], $overrides));
    }

    // ── The three blocked states ──────────────────────────────────────────

    /** TASK-115 deliverable 1, state 1. */
    public function test_unverified_self_registered_agent_is_blocked_with_a_resend_affordance(): void
    {
        $company = Company::factory()->create();
        $user = $this->selfRegisteredAgent($company);

        $this->postLogin(['email' => $user->email, 'password' => 'password123'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'email_unverified')
            ->assertJsonPath('can_resend_verification', true)
            ->assertJsonPath('can_reapply', false)
            ->assertJsonPath('rejection_reason', null);

        // The session must NOT survive a blocked login — otherwise the gate
        // would stop the login screen and nothing else.
        $this->assertGuest();
    }

    /** TASK-115 deliverable 1, state 2. */
    public function test_verified_but_pending_agent_is_blocked_with_the_waiting_state(): void
    {
        $company = Company::factory()->create();
        $user = $this->selfRegisteredAgent($company, ['email_verified_at' => now()]);

        $this->postLogin(['email' => $user->email, 'password' => 'password123'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'approval_pending')
            ->assertJsonPath('can_resend_verification', false)
            ->assertJsonPath('can_reapply', false);

        $this->assertGuest();
    }

    /**
     * TASK-115 deliverable 1, state 3 — and ADR-005 decision 7: the response
     * carries the stored reason AND `can_reapply: true`, so the SPA cannot
     * render rejection as a permanent lockout.
     */
    public function test_rejected_agent_is_blocked_with_the_reason_and_may_reapply(): void
    {
        $company = Company::factory()->create();
        $user = $this->selfRegisteredAgent($company, [
            'email_verified_at' => now(),
            'agent_approval_status' => AgentApprovalStatus::Rejected,
            'approval_rejection_reason' => 'เอกสารไม่ครบ',
        ]);

        $this->postLogin(['email' => $user->email, 'password' => 'password123'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'approval_rejected')
            ->assertJsonPath('can_reapply', true)
            ->assertJsonPath('rejection_reason', 'เอกสารไม่ครบ');

        $this->assertGuest();
    }

    /**
     * The ORDER deviation from TASK-021, pinned deliberately (see
     * LoginGateService's comment). An Admin can reject someone who has not
     * verified yet, because the approval queue lists every Pending row
     * regardless of verification. Such a person must be told they were
     * rejected — not sent off to verify an email that will change nothing.
     */
    public function test_a_rejected_and_unverified_agent_is_told_about_the_rejection_not_the_email(): void
    {
        $company = Company::factory()->create();
        $user = $this->selfRegisteredAgent($company, [
            'email_verified_at' => null,
            'agent_approval_status' => AgentApprovalStatus::Rejected,
            'approval_rejection_reason' => 'ไม่ผ่านการตรวจสอบ',
        ]);

        $this->postLogin(['email' => $user->email, 'password' => 'password123'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'approval_rejected');
    }

    /** The three blocked states must be distinguishable FROM EACH OTHER. */
    public function test_the_three_blocked_states_return_three_different_error_codes(): void
    {
        $company = Company::factory()->create();

        $unverified = $this->selfRegisteredAgent($company);
        $pending = $this->selfRegisteredAgent($company, ['email_verified_at' => now()]);
        $rejected = $this->selfRegisteredAgent($company, [
            'email_verified_at' => now(),
            'agent_approval_status' => AgentApprovalStatus::Rejected,
        ]);

        $codes = collect([$unverified, $pending, $rejected])->map(
            fn (User $u) => $this->postLogin(['email' => $u->email, 'password' => 'password123'])->json('error_code')
        );

        $this->assertCount(3, $codes->unique());
    }

    // ── Who the gate must NOT touch ───────────────────────────────────────

    /** ADR-025 §8: "Company Admin / Super Admin are unaffected." */
    public function test_a_company_admin_logs_in_normally_even_when_unverified_and_not_approved(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create([
            'company_id' => $company->id,
            'password' => bcrypt('password123'),
            // Deliberately the worst-case row: if the gate leaked past the
            // isAgent() early return, this would be blocked twice over.
            'email_verified_at' => null,
            'agent_approval_status' => AgentApprovalStatus::Pending,
        ]);

        $this->postLogin(['email' => $admin->email, 'password' => 'password123'])->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_super_admin_logs_in_normally(): void
    {
        $superAdmin = User::factory()->superAdmin()->create([
            'password' => bcrypt('password123'),
            'email_verified_at' => null,
            'agent_approval_status' => AgentApprovalStatus::Pending,
        ]);

        $this->postLogin(['email' => $superAdmin->email, 'password' => 'password123'])->assertOk();
    }

    public function test_an_approved_and_verified_agent_logs_in_normally(): void
    {
        $company = Company::factory()->create();
        $user = $this->selfRegisteredAgent($company, [
            'email_verified_at' => now(),
            'agent_approval_status' => AgentApprovalStatus::Approved,
        ]);

        $this->postLogin(['email' => $user->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    /**
     * THE REGRESSION TEST THAT MATTERS MOST (TASK-021 acceptance criterion
     * 2). UserService::create() — the "Manage Agents" flow — never sets
     * email_verified_at, so EVERY agent an Admin has ever created is
     * unverified in the database. If the gate keyed on hasVerifiedEmail()
     * alone, all of them would be locked out on deploy. isSelfRegistered()
     * is what stops that; this test is what stops someone "simplifying" it.
     *
     * ── WHY THIS GOES THROUGH THE REAL ENDPOINT (TASK-119 / QA finding D3) ──
     * It used to build the row with User::factory() and hand-set nulls. That
     * asserted the gate's behaviour for a row shaped like an admin-created
     * agent — which is not the same claim, because the shape was the test
     * author's belief about the creation path rather than the creation path's
     * output. The regression it exists to prevent lives on the OTHER side of
     * that gap:
     *
     *   * someone adds `agent_approval_status` to StoreUserRequest's rules (or
     *     defaults it to 'pending' there),
     *   * or sets it in User::$attributes, or changes the column default away
     *     from 'approved',
     *   * or adds email verification to UserService::create(),
     *
     * and the hand-built version keeps passing while every agent an Admin
     * creates is locked out of login on the next deploy. So: create through
     * POST /api/v1/users as a Company Admin, then actually log in as that
     * agent. Both halves are needed — the login is the assertion, the real
     * creation path is what makes it mean anything.
     */
    public function test_an_admin_created_agent_is_not_subject_to_the_email_verification_gate(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // The real "Manage Agents" flow: StoreUserRequest + UserService::create().
        // Note what is NOT sent — no agent_approval_status, no
        // email_verified_at, no invite code. Whatever those become is the
        // creation path's decision, and that is exactly what is under test.
        $this->actingAs($admin)
            ->postJson('/api/v1/users', [
                'first_name' => 'Somchai',
                'last_name' => 'Jaidee',
                'email' => 'somchai@thailife.test',
                'password' => 'TempPass123',
                'role' => 'agent',
            ])
            ->assertCreated();

        $created = User::withoutGlobalScopes()
            ->where('email', 'somchai@thailife.test')
            ->firstOrFail();

        // Read back from the database rather than trusted: these three are the
        // invariants the gate depends on, so if the login below ever breaks,
        // whichever of these broke first names the cause instead of leaving a
        // bare 403 to diagnose.
        $this->assertNull($created->email_verified_at, 'Admin-created agents are unverified by design; the gate must not key on that alone.');
        $this->assertFalse($created->isSelfRegistered(), 'No invite code and no recruit link — this is what exempts the account from email verification.');
        $this->assertSame(AgentApprovalStatus::Approved, $created->agent_approval_status, 'ADR-005: the Admin creating the account IS the approval.');

        // Drop the Admin's authenticated session before logging in as somebody
        // else — otherwise this test would be posting a login from inside an
        // existing session and the result would be ambiguous.
        Auth::forgetGuards();
        $this->flushSession();

        // The actual regression: this account can hold a session.
        // The data.id assertion is deliberate — asserting 200 alone would
        // still pass if a leftover session answered for the wrong user.
        $this->postLogin(['email' => 'somchai@thailife.test', 'password' => 'TempPass123'])
            ->assertOk()
            ->assertJsonPath('data.id', $created->id);
    }

    // ── Non-enumeration + throttling ──────────────────────────────────────

    /**
     * The boundary that actually matters: an unknown email and a real email
     * with the wrong password must be INDISTINGUISHABLE. Same status, same
     * body. A blocked account is only ever reachable with a correct password
     * (see the 403 tests above), so it is not on this boundary.
     */
    public function test_a_wrong_password_and_an_unknown_email_are_indistinguishable(): void
    {
        $company = Company::factory()->create();
        $blocked = $this->selfRegisteredAgent($company);

        $wrongPassword = $this->postLogin([
            'email' => $blocked->email,
            'password' => 'definitely-not-the-password',
        ]);

        // A fresh throttle key (different email) so the second call is not
        // answered by the lockout instead of the credential check.
        $unknownEmail = $this->postLogin([
            'email' => 'nobody-here@thailife.test',
            'password' => 'definitely-not-the-password',
        ]);

        $wrongPassword->assertStatus(422);
        $unknownEmail->assertStatus(422);
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
    }

    /**
     * A blocked login must not look like a wrong password either — that was
     * the whole point of TASK-021 ("never a generic 'invalid credentials'
     * for these cases").
     */
    public function test_a_blocked_login_is_not_confused_with_a_wrong_password(): void
    {
        $company = Company::factory()->create();
        $user = $this->selfRegisteredAgent($company, ['email_verified_at' => now()]);

        $this->postLogin(['email' => $user->email, 'password' => 'password123'])
            ->assertStatus(403)
            ->assertJsonMissingPath('errors.email');
    }

    /**
     * Existing throttling/lockout behaviour is untouched: 5 failed attempts
     * on the same email+IP still lock the key out.
     */
    public function test_repeated_wrong_passwords_still_lock_out_after_five_attempts(): void
    {
        $company = Company::factory()->create();
        $user = $this->selfRegisteredAgent($company);

        for ($i = 0; $i < 5; $i++) {
            $this->postLogin(['email' => $user->email, 'password' => 'wrong'])->assertStatus(422);
        }

        // The 6th is refused by ensureIsNotRateLimited() before the
        // credential check — even with the CORRECT password.
        $this->postLogin(['email' => $user->email, 'password' => 'password123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    // ── Resend verification (TASK-021 item 3) ─────────────────────────────

    public function test_resend_verification_sends_the_email_for_an_unverified_self_registrant(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $user = $this->selfRegisteredAgent($company);

        $this->postJson('/api/v1/register/resend-verification-email', ['email' => $user->email])
            ->assertOk();

        Notification::assertSentTo($user, VerifyRegistrationEmailNotification::class);
    }

    /**
     * The endpoint must not become a free membership oracle: an unknown
     * address gets the same 200 and the same body as a real one, and no mail
     * is sent.
     */
    public function test_resend_verification_answers_identically_for_an_unknown_email(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $known = $this->selfRegisteredAgent($company);

        $real = $this->postJson('/api/v1/register/resend-verification-email', ['email' => $known->email]);
        $fake = $this->postJson('/api/v1/register/resend-verification-email', ['email' => 'nobody-here@thailife.test']);

        $real->assertOk();
        $fake->assertOk();
        $this->assertSame($real->json(), $fake->json());

        Notification::assertSentTimes(VerifyRegistrationEmailNotification::class, 1);
    }

    public function test_resend_verification_sends_nothing_to_an_already_verified_or_rejected_account(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $verified = $this->selfRegisteredAgent($company, ['email_verified_at' => now()]);
        $rejected = $this->selfRegisteredAgent($company, [
            'agent_approval_status' => AgentApprovalStatus::Rejected,
        ]);

        $this->postJson('/api/v1/register/resend-verification-email', ['email' => $verified->email])->assertOk();
        $this->postJson('/api/v1/register/resend-verification-email', ['email' => $rejected->email])->assertOk();

        Notification::assertNothingSent();
    }

    /** Section 6 — every public endpoint is throttled; this one is 5/min. */
    public function test_resend_verification_is_rate_limited(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $user = $this->selfRegisteredAgent($company);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/register/resend-verification-email', ['email' => $user->email])
                ->assertOk();
        }

        $this->postJson('/api/v1/register/resend-verification-email', ['email' => $user->email])
            ->assertStatus(429);
    }
}
