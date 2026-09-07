<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TASK-247 — what a refused login is allowed to tell you.
 *
 * The screen could say "wrong password" and it could say "locked out". It
 * could not say "one attempt left", and when the lockout arrived it dropped
 * the one fact that makes a lockout survivable: how long. A person then
 * refreshes for an unknown number of minutes, and the usual next step is
 * asking an admin to reset a password that was never wrong.
 *
 * The line these tests hold is where the extra help stops. `attempts_remaining`
 * counts the throttle key — email + IP — which is incremented identically for
 * a wrong password and for an address with no account behind it. So the number
 * is the same in both cases and reveals nothing the caller could not count by
 * themselves. The single non-enumerable branch is asserted directly below,
 * because that is the property that would be quietly lost if somebody later
 * made this "more helpful".
 */
class LoginFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ngPassword';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('somchai@example.com|127.0.0.1');
        RateLimiter::clear('nobody@example.com|127.0.0.1');

        $this->user = User::factory()->companyAdmin()->create([
            'company_id' => Company::factory()->create()->id,
            'email' => 'somchai@example.com',
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * POST /api/v1/login the way the SPA actually does it.
     *
     * The Origin header is not decoration: Sanctum's
     * EnsureFrontendRequestsAreStateful only starts a session when the origin
     * matches config('sanctum.stateful'), and without a session
     * Auth::attempt() cannot run at all. Same helper as LoginGateTest, for the
     * same reason, including pinning the stateful list rather than trusting a
     * developer's local .env.
     */
    private function attempt(string $password, string $email = 'somchai@example.com'): TestResponse
    {
        config(['sanctum.stateful' => ['agent.localhost']]);

        return $this->withHeader('Origin', 'http://agent.localhost')
            ->postJson('/api/v1/login', ['email' => $email, 'password' => $password]);
    }

    // ── Counting down ────────────────────────────────────────────────

    public function test_a_wrong_password_says_how_many_attempts_are_left(): void
    {
        $this->attempt('wrong')
            ->assertStatus(422)
            ->assertJsonPath('attempts_remaining', 4)
            ->assertJsonPath('lockout_seconds', null);
    }

    public function test_the_count_falls_with_each_attempt(): void
    {
        $this->attempt('wrong')->assertJsonPath('attempts_remaining', 4);
        $this->attempt('wrong')->assertJsonPath('attempts_remaining', 3);
        $this->attempt('wrong')->assertJsonPath('attempts_remaining', 2);
    }

    public function test_the_last_attempt_reports_zero_remaining(): void
    {
        // Not "locked out" yet — the fifth failure is the last one allowed,
        // and the sixth is what meets the closed throttle. Saying 0 here is
        // what turns the next refusal into something the reader expected.
        for ($i = 0; $i < 4; $i++) {
            $this->attempt('wrong');
        }

        $this->attempt('wrong')->assertJsonPath('attempts_remaining', 0);
    }

    public function test_a_correct_password_clears_the_count(): void
    {
        // The throttle exists to stop GUESSING, and a correct password ends
        // the guessing. Someone who mistypes twice and then signs in starts
        // from a full budget next time.
        $this->attempt('wrong');
        $this->attempt('wrong');
        $this->attempt(self::PASSWORD)->assertOk();

        $this->post('/api/v1/logout');

        $this->attempt('wrong')->assertJsonPath('attempts_remaining', 4);
    }

    // ── Locked out ───────────────────────────────────────────────────

    public function test_the_lockout_says_how_long_it_lasts(): void
    {
        /*
         * The actual defect. The wait was interpolated into an English
         * sentence, and the screen — which owns its own Thai copy — could only
         * render "try again later". A number is a number in both languages.
         */
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('wrong');
        }

        $response = $this->attempt('wrong')->assertStatus(422);

        $this->assertNull($response->json('attempts_remaining'));
        $this->assertIsInt($response->json('lockout_seconds'));
        $this->assertGreaterThan(0, $response->json('lockout_seconds'));
    }

    public function test_the_lockout_refuses_a_correct_password_too(): void
    {
        // Otherwise the throttle would be trivially bypassable by whoever
        // eventually guesses right, which is the one caller it must stop.
        for ($i = 0; $i < 6; $i++) {
            $this->attempt('wrong');
        }

        $this->attempt(self::PASSWORD)
            ->assertStatus(422)
            ->assertJsonPath('attempts_remaining', null);
    }

    // ── What it still refuses to say ─────────────────────────────────

    public function test_an_unknown_address_is_answered_exactly_like_a_wrong_password(): void
    {
        /*
         * The property that must survive every future "let's be more helpful"
         * edit. Same status, same message, same field, same count — because
         * the throttle key is email+IP and is hit identically either way.
         *
         * Asserted as a whole-body comparison rather than key by key, so a new
         * key that happens to differ between the two cases fails here rather
         * than shipping as an enumeration oracle.
         */
        $unknown = $this->attempt('wrong', 'nobody@example.com');
        $wrong = $this->attempt('wrong');

        $unknown->assertStatus(422);
        $wrong->assertStatus(422);
        $this->assertSame($unknown->json(), $wrong->json());
    }

    public function test_the_response_still_carries_the_field_error_shape(): void
    {
        // Additive, not a replacement: the agent portal and every existing
        // test read `errors.email`.
        $this->attempt('wrong')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }
}
