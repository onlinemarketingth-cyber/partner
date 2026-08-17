<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Bug fix — CLAUDE.md Section 3 ("strictly a RESTful API ... Blade
// forbidden"). Before this fix, hitting any auth:sanctum route while
// logged out via a plain browser navigation (Accept: text/html, not an
// XHR/fetch call) crashed with an unhandled 500
// RouteNotFoundException("Route [login] not defined") — Laravel's
// default unauthenticated() handler tried to redirect to a web login
// page that doesn't exist in this API-only app. This app has zero web
// routes to redirect to, so authentication failures must always return
// a clean JSON 401, regardless of the request's Accept header.
class UnauthenticatedRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_json_request_gets_a_clean_401(): void
    {
        $this->getJson('/api/v1/leaderboard?company_id=1')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    /**
     * The actual regression: a plain (non-XHR) GET request — like typing
     * the URL directly into a browser — sends Accept: text/html, not
     * application/json. Laravel's $request->expectsJson() is false for
     * this, which is exactly the path that used to trigger the
     * RouteNotFoundException. Must still return JSON 401, not a 500 or
     * an HTML redirect.
     */
    public function test_unauthenticated_plain_browser_request_gets_json_401_not_a_500(): void
    {
        $response = $this->get('/api/v1/leaderboard?company_id=1');

        $response->assertUnauthorized();
        $response->assertHeader('content-type', 'application/json');
    }

    public function test_unauthenticated_request_to_a_write_endpoint_gets_a_clean_401(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->postJson('/api/v1/level-thresholds', ['level_number' => 1, 'xp_required' => 0])
            ->assertUnauthorized();
    }
}
