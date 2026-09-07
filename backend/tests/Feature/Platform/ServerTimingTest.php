<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-07 (human: "สร้าง link ครั้งแรก และสมัครสมาชิกในระบบทำไมช้า").
 *
 * The question was unanswerable except by reading code and guessing, and the
 * two candidates pull in opposite directions: registration sends its
 * verification email synchronously (seconds of SMTP, no queries), while a slow
 * list is the opposite shape (many queries, no waiting). One duration cannot
 * tell them apart; total + database time + query count can.
 *
 * The tests that matter here are the two about it being OFF. A timing header
 * is a side channel on exactly the endpoints this API works hardest to make
 * uniform — LoginRequest answers an unknown address and a wrong password
 * identically, byte for byte, and a duration handed back in a header is the
 * distinction leaking out the other side. So the default is off, and being off
 * has to mean nothing is measured at all, not "measured and hidden".
 */
class ServerTimingTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        return User::factory()->companyAdmin()->create([
            'company_id' => Company::factory()->create()->id,
        ]);
    }

    public function test_nothing_is_reported_by_default(): void
    {
        config(['app.server_timing' => false]);

        $this->actingAs($this->actor())
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertHeaderMissing('Server-Timing')
            ->assertHeaderMissing('X-Query-Count');
    }

    public function test_a_public_endpoint_is_silent_too(): void
    {
        /*
         * The one that matters. `/register` and `/login` are reachable without
         * credentials and are deliberately uniform about whether an address
         * exists; a duration on the response would be the difference those
         * branches refuse to state.
         */
        config(['app.server_timing' => false]);

        $this->postJson('/api/v1/register/check-email', ['email' => 'nobody@example.com'])
            ->assertHeaderMissing('Server-Timing');
    }

    public function test_it_reports_total_time_and_database_time_when_switched_on(): void
    {
        config(['app.server_timing' => true]);

        $response = $this->actingAs($this->actor())
            ->getJson('/api/v1/products')
            ->assertOk();

        $timing = $response->headers->get('Server-Timing');

        $this->assertMatchesRegularExpression('/app;desc="total";dur=[\d.]+/', $timing);
        $this->assertMatchesRegularExpression('/db;desc="\d+ queries";dur=[\d.]+/', $timing);
    }

    public function test_the_query_count_is_a_real_count(): void
    {
        // The number that separates "waiting on something outside the
        // database" from "asking the database far too often" — so it has to be
        // the actual count, not a placeholder that happens to render.
        config(['app.server_timing' => true]);

        $response = $this->actingAs($this->actor())
            ->getJson('/api/v1/products')
            ->assertOk();

        $this->assertGreaterThan(0, (int) $response->headers->get('X-Query-Count'));
    }
}
