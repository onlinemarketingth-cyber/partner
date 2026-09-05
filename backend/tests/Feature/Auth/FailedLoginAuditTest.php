<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * TASK-240 — the failed half of the login trail.
 *
 * Successful logins have been audited since 2026-08-21. Failures were not
 * recorded anywhere, so the first question anyone asks of a login trail —
 * "has somebody been trying to get into this account?" — had no answer.
 *
 * The three rules below are each here because the OBVIOUS implementation of
 * a login log is harmful, and each is asserted rather than trusted:
 *
 *   1. it must not become a credential store, or a list of probed addresses
 *   2. it must not let a bot flood the trail it is meant to appear in
 *   3. a refused GATE (right password, blocked account) is not a failed
 *      password, and collapsing the two makes an attack look like a person
 *      waiting to be approved
 */
class FailedLoginAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('someone@example.com|127.0.0.1');

        $company = Company::factory()->create();
        $this->agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'email' => 'someone@example.com',
            'password' => 'correct horse 8',
        ]);
    }

    /** POST /login the way the SPA does — see LoginGateTest for the Origin note. */
    private function attempt(string $email, string $password): \Illuminate\Testing\TestResponse
    {
        config(['sanctum.stateful' => ['agent.localhost']]);

        return $this->withHeader('Origin', 'http://agent.localhost')
            ->postJson('/api/v1/login', ['email' => $email, 'password' => $password]);
    }

    public function test_a_wrong_password_is_recorded_against_the_account(): void
    {
        $this->attempt('someone@example.com', 'wrong one')->assertStatus(422);

        $row = AuditLog::where('action', 'auth.login_failed')->firstOrFail();

        $this->assertSame($this->agent->id, $row->actor_user_id);
        $this->assertTrue($row->new_values['known_account']);
        // The field that makes these rows worth keeping.
        $this->assertNotNull($row->ip_address);
    }

    public function test_the_attempted_password_never_reaches_the_trail(): void
    {
        // Rule 1, the half everybody remembers.
        $this->attempt('someone@example.com', 'hunter2-is-my-password')->assertStatus(422);

        $this->assertStringNotContainsString('hunter2-is-my-password', AuditLog::all()->toJson());
    }

    public function test_an_address_with_no_account_is_recorded_without_being_named(): void
    {
        /*
         * Rule 1, the half that gets forgotten. Writing the attempted address
         * would turn this table into a list of addresses somebody probed —
         * readable by everyone who can open the audit screen. The attempt is
         * still worth a row; it just identifies nobody.
         */
        $this->attempt('probe@attacker.test', 'anything')->assertStatus(422);

        $row = AuditLog::where('action', 'auth.login_failed')->firstOrFail();

        $this->assertNull($row->actor_user_id);
        $this->assertFalse($row->new_values['known_account']);
        $this->assertStringNotContainsString('probe@attacker.test', AuditLog::all()->toJson());
    }

    public function test_ten_guesses_write_five_failures_and_exactly_one_lockout(): void
    {
        /*
         * Rule 2. Without a ceiling, a bot pointed at one address writes a
         * row per guess forever and drowns the trail it is supposed to
         * appear in.
         *
         * SIX ROWS FOR TEN GUESSES: five failures fit under the throttle,
         * then one lockout, then silence — every later attempt is refused by
         * ensureIsNotRateLimited() before any audit code runs.
         */
        foreach (range(1, 10) as $ignored) {
            $this->attempt('someone@example.com', 'wrong one');
        }

        $this->assertSame(5, AuditLog::where('action', 'auth.login_failed')->count());

        /*
         * ONE lockout row, written by RecordAuthLockout — which had been
         * registered TWICE (explicitly in AppServiceProvider and again by
         * Laravel's listener discovery) since the day it shipped, so every
         * lockout wrote two identical rows. This assertion is what found it.
         *
         * It also holds the flood ceiling: the Lockout event fires on every
         * attempt made while locked, so without the guard in the listener a
         * bot writes one row per guess, forever, into the trail it is
         * supposed to stand out in.
         */
        $this->assertSame(1, AuditLog::where('action', 'auth.lockout')->count());
    }

    public function test_a_blocked_account_is_a_different_event_from_a_wrong_password(): void
    {
        /*
         * Rule 3. The password was RIGHT. Recording this as a failed login
         * would make a deactivated employee's honest attempt look identical
         * to somebody guessing, and the reason is the whole value of the row.
         */
        /*
         * AWAITING APPROVAL, chosen carefully. Two other blocks look like
         * they would do and do not:
         *   - a DEACTIVATED user is soft-deleted and invisible to the auth
         *     provider, so attempt() fails first and it is genuinely recorded
         *     as a credential failure;
         *   - the UNVERIFIED-email gate applies only to self-registered
         *     agents (LoginGateService reads isSelfRegistered()), and a
         *     factory agent is not one.
         * Pending blocks any agent, after the password was accepted — which
         * is the case this row exists for.
         */
        $this->agent->forceFill(['agent_approval_status' => 'pending'])->save();

        $this->attempt('someone@example.com', 'correct horse 8')->assertStatus(403);

        $this->assertSame(0, AuditLog::where('action', 'auth.login_failed')->count());

        $row = AuditLog::where('action', 'auth.login_blocked')->firstOrFail();
        $this->assertSame($this->agent->id, $row->actor_user_id);
        $this->assertNotEmpty($row->new_values['reason']);
    }

    public function test_a_successful_login_writes_no_failure_row(): void
    {
        $this->attempt('someone@example.com', 'correct horse 8')->assertOk();

        $this->assertSame(0, AuditLog::whereIn('action', ['auth.login_failed', 'auth.lockout', 'auth.login_blocked'])->count());
        // …and the success row that already existed still arrives.
        $this->assertSame(1, AuditLog::where('action', 'auth.login')->count());
    }
}
