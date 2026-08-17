<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// Bug fix (2026-08-02, human question: "ระบบจำแบบ login แบบ Facebook ทำ
// อย่างไร") — LoginView's "จดจำฉัน" checkbox existed in the UI but was
// never sent to POST /login, so Laravel's built-in remember-me (a
// long-lived `remember_token` cookie via Auth::attempt($credentials,
// $remember) — the same mechanism most sites, including Facebook, use to
// skip login on return visits) was permanently dormant. This locks in the
// fix: `remember` now actually reaches LoginRequest::authenticate().
class LoginRememberMeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * POST /api/v1/login the way the SPA actually does it.
     *
     * Fix (2026-08-03, surfaced by `php artisan test`: "Session store not
     * set on request"). AuthController::login() calls
     * $request->session()->regenerate() — that only works when Sanctum's
     * EnsureFrontendRequestsAreStateful middleware (wired by
     * $middleware->statefulApi() in bootstrap/app.php) has decided the
     * request came "from the frontend" and therefore pushed the session +
     * cookie-auth middleware onto the stack. It decides that by matching
     * the Origin/Referer host against config('sanctum.stateful').
     *
     * A bare postJson() sends no Origin, so the request is treated as a
     * stateless token request, no session is started, and the controller
     * blows up. The real SPA always sends an Origin, so this is a test
     * artefact, not a production bug — the fix is to reproduce the real
     * conditions rather than to weaken the controller.
     *
     * The stateful list is pinned here instead of relying on
     * SANCTUM_STATEFUL_DOMAINS from .env: the suite must not depend on a
     * developer's local env values (they differ per machine and .env is
     * not committed).
     *
     * @param  array<string, mixed>  $payload
     */
    private function postLogin(array $payload): TestResponse
    {
        config(['sanctum.stateful' => ['agent.localhost']]);

        return $this->withHeader('Origin', 'http://agent.localhost')
            ->postJson('/api/v1/login', $payload);
    }

    public function test_login_with_remember_true_sets_a_remember_token(): void
    {
        $company = Company::factory()->create();
        // remember_token MUST be nulled explicitly — see the sibling test's
        // comment; UserFactory seeds a random one, which would make both
        // assertions in this file meaningless.
        $user = User::factory()->agent()->create([
            'company_id' => $company->id,
            'password' => bcrypt('password123'),
            'remember_token' => null,
        ]);

        $this->postLogin([
            'email' => $user->email,
            'password' => 'password123',
            'remember' => true,
        ])->assertOk();

        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_login_without_remember_does_not_set_a_remember_token(): void
    {
        $company = Company::factory()->create();
        // Fix (2026-08-03, `php artisan test`: "Failed asserting that
        // 'GNfyMDHERl' is null"). Laravel's stock UserFactory seeds
        // 'remember_token' => Str::random(10), so the user arrives at this
        // test ALREADY carrying a token — the assertion below was reading
        // factory noise, not login behaviour. Nulling it here is what makes
        // "login did not create one" an actual claim.
        $user = User::factory()->agent()->create([
            'company_id' => $company->id,
            'password' => bcrypt('password123'),
            'remember_token' => null,
        ]);

        $this->postLogin([
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $this->assertNull($user->fresh()->remember_token);
    }
}
