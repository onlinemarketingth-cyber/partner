<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Api\V1\AuthController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * 2026-09-09 (human: "ทำให้อายุการ Login นานกว่านี้แบบ Facebook หรือ YouTube
 * ทำอย่างไร").
 *
 * The agent portal issued a flat 12-hour token with NO renewal, so an agent
 * using the app all day was signed out in the middle of using it — the one
 * moment a timeout is least defensible, because the person is right there and
 * demonstrably still themselves. And the "จดจำฉัน" box on that login screen
 * did nothing whatsoever: the portal authenticates with a bearer token, and
 * remember-me is a cookie mechanism, so ticking it changed no behaviour at all.
 *
 * ── WHAT "LIKE FACEBOOK" IS ──
 *
 * Not a long fuse. A fuse that RESETS every time you touch it. The number
 * stops meaning "how long you may stay signed in" and starts meaning "how long
 * you may stop before signing in again" — the question a person can actually
 * reason about.
 *
 * These tests pin both halves: the window the box chooses, and the sliding
 * that makes the window mean idleness rather than age.
 */
class SessionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private ?string $plainTextToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        $this->agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'email' => 'agent@example.com',
            'password' => Hash::make('password123'),
        ]);
    }

    /**
     * Sign in the way the portal does, and keep the plaintext token.
     *
     * Sanctum returns it exactly once and stores only a hash, so it has to be
     * captured here — it cannot be recovered from the table afterwards.
     *
     * @return TestResponse
     */
    private function loginAsPortal(bool $remember)
    {
        $response = $this->withHeader('X-Auth-Mode', 'token')->postJson('/api/v1/login', [
            'email' => 'agent@example.com',
            'password' => 'password123',
            'remember' => $remember,
        ]);

        $this->plainTextToken = $response->json('token');

        /*
         * Drop the session the login also created.
         *
         * LoginRequest calls Auth::attempt(), which logs the user into the WEB
         * guard as well — harmless in production, where the portal runs on a
         * non-stateful domain and never sends that cookie back. In a test the
         * client keeps it, so every following request would authenticate by
         * session and carry a TransientToken: the bearer token would never be
         * looked at, the middleware would no-op, and an expired token would
         * still be accepted. Both of which this file would then have reported
         * as the code being wrong.
         */
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $response;
    }

    private function tokenRow(): PersonalAccessToken
    {
        return PersonalAccessToken::query()->latest('id')->firstOrFail();
    }

    // ── The window "จดจำฉัน" chooses ─────────────────────────────────

    public function test_ticking_remember_buys_thirty_days(): void
    {
        $this->loginAsPortal(true)->assertOk();

        $token = $this->tokenRow();
        $this->assertSame(AuthController::TOKEN_NAME_REMEMBER, $token->name);
        $this->assertEqualsWithDelta(60 * 24 * 30, now()->diffInMinutes($token->expires_at), 2);
    }

    public function test_leaving_it_unticked_buys_one_day(): void
    {
        // A shared or borrowed phone is a real thing, and one number for both
        // cases would have to be the short one — which is the complaint.
        $this->loginAsPortal(false)->assertOk();

        $token = $this->tokenRow();
        $this->assertSame(AuthController::TOKEN_NAME, $token->name);
        $this->assertEqualsWithDelta(60 * 24, now()->diffInMinutes($token->expires_at), 2);
    }

    public function test_the_box_actually_changes_something_now(): void
    {
        /*
         * The regression that matters most to a person: before this, both
         * branches produced the identical 12-hour token, so the control on
         * screen was decoration. A control that visibly does nothing is worse
         * than no control.
         */
        $this->loginAsPortal(false)->assertOk();
        $short = $this->tokenRow()->expires_at;

        $this->loginAsPortal(true)->assertOk();
        $long = $this->tokenRow()->expires_at;

        $this->assertTrue($long->greaterThan($short));
    }

    // ── The sliding ──────────────────────────────────────────────────

    public function test_using_the_app_past_halfway_pushes_the_expiry_out(): void
    {
        $this->loginAsPortal(false)->assertOk();
        $token = $this->tokenRow();

        // Two-thirds of the way through: still valid, past halfway.
        $token->forceFill(['expires_at' => now()->addHours(8)])->save();

        $this->travel(1)->minute();
        $this->withToken($this->freshPlainTextToken())->getJson('/api/v1/products')->assertOk();

        $this->assertGreaterThan(60 * 20, now()->diffInMinutes($this->tokenRow()->expires_at));
    }

    public function test_a_token_with_most_of_its_window_left_is_not_rewritten(): void
    {
        /*
         * Renewing on every request would put a database write on every API
         * call in the app — a dashboard alone makes half a dozen — to move a
         * date with weeks left on it. Past halfway only; the guarantee is
         * identical and the cost collapses.
         */
        $this->loginAsPortal(true)->assertOk();
        $before = $this->tokenRow()->expires_at;

        $this->withToken($this->freshPlainTextToken())->getJson('/api/v1/products')->assertOk();

        $this->assertTrue($before->equalTo($this->tokenRow()->expires_at));
    }

    public function test_an_expired_token_is_not_resurrected(): void
    {
        /*
         * The failure this middleware could most easily cause: pushing the
         * date forward on a token that should have sent the person back to
         * the login screen. Sanctum rejects the request first — and the
         * expiry must still be in the past afterwards.
         */
        $this->loginAsPortal(false)->assertOk();
        $plain = $this->freshPlainTextToken();

        $token = $this->tokenRow();
        $token->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withToken($plain)->getJson('/api/v1/products')->assertUnauthorized();

        $this->assertTrue($this->tokenRow()->expires_at->isPast());
    }

    private function freshPlainTextToken(): string
    {
        return $this->plainTextToken ?? throw new \RuntimeException('ยังไม่ได้ล็อกอิน');
    }
}
