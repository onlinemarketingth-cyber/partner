<?php

namespace Tests\Feature\Theme;

use App\Models\Company;
use App\Models\CompanyThemeSetting;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\ThemePreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-08 (human: "ผมเลือกใช้ชุดสี Live to 100 Club แล้วทำไมยังไม่เปลี่ยน").
 *
 * They had applied it. The row was written. The screen then re-read the theme
 * through GET /public/theme/{slug} — the PUBLIC, unauthenticated, pre-login
 * branding endpoint — because that was the only read that let a Super Admin
 * name a company, and `/me/theme` answers for the caller's own company, which
 * a Super Admin does not have.
 *
 * A public GET is cacheable by everything between the browser and PHP. So the
 * one URL on the theme screen that a cache is allowed to answer from memory
 * was the one the screen used to show the result of the admin's own click.
 * "I pressed it and nothing changed" is the correct description of that.
 *
 * The other two consequences were quieter and just as real: a DEACTIVATED
 * company 404s on the public endpoint on purpose (a closed tenant has no
 * branded front door), so a Super Admin could not open the theme of a company
 * they had switched off — which is one of the reasons they would want to. And
 * admin editing shared the public 60/min throttle with real login traffic.
 */
class AdminThemeReadTest extends TestCase
{
    use RefreshDatabase;

    private Company $genesenn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->genesenn = Company::factory()->create(['name' => 'GENESENN', 'slug' => 'genesenn']);

        CompanyThemeSetting::withoutGlobalScopes()->create([
            'company_id' => $this->genesenn->id,
            'primary_hex' => '#B08D46',
        ]);
    }

    public function test_a_super_admin_reads_the_theme_of_the_company_they_name(): void
    {
        // The read that did not exist: a Super Admin belongs to no company, so
        // "my company's theme" has no answer for them.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson("/api/v1/company-theme?company_id={$this->genesenn->id}")
            ->assertOk()
            ->assertJsonPath('data.primary_hex', '#B08D46');
    }

    public function test_the_answer_may_not_be_cached(): void
    {
        /*
         * The whole point. This endpoint exists because the screen was reading
         * a CACHEABLE public URL, so an uncacheable answer is the property
         * being bought — not an optimisation detail. Applying a preset and
         * re-reading must show the admin what they just did, every time.
         */
        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson("/api/v1/company-theme?company_id={$this->genesenn->id}")
            ->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_it_answers_for_a_deactivated_company(): void
    {
        /*
         * Where the public endpoint deliberately says 404 (§3.4 — a closed
         * tenant has no branded front door) and this one must not: switching a
         * company back on is exactly when an admin opens its settings.
         */
        $this->genesenn->update(['is_active' => false]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson("/api/v1/company-theme?company_id={$this->genesenn->id}")
            ->assertOk()
            ->assertJsonPath('data.primary_hex', '#B08D46');

        $this->getJson('/api/v1/public/theme/genesenn')->assertNotFound();
    }

    public function test_a_company_admins_company_id_is_ignored(): void
    {
        /*
         * The reason this route is not restricted to Super Admins: it hands a
         * Company Admin exactly what /me/theme already hands them, and a
         * company_id they type is discarded rather than obeyed. Widening the
         * read would have been the easy mistake — one query parameter away
         * from any admin reading any tenant's branding.
         */
        $other = Company::factory()->create(['name' => 'Thai Life', 'slug' => 'thai-life']);
        CompanyThemeSetting::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'primary_hex' => '#123456',
        ]);

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $this->genesenn->id]))
            ->getJson("/api/v1/company-theme?company_id={$other->id}")
            ->assertOk()
            ->assertJsonPath('data.primary_hex', '#B08D46');
    }

    public function test_an_agents_company_id_is_ignored_too(): void
    {
        // Agents already read /me/theme to paint the portal, so this route is
        // not a new door — but only if it forces their own company here too.
        $other = Company::factory()->create(['name' => 'Thai Life', 'slug' => 'thai-life']);
        CompanyThemeSetting::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'primary_hex' => '#123456',
        ]);

        $this->actingAs(User::factory()->agent()->create(['company_id' => $this->genesenn->id]))
            ->getJson("/api/v1/company-theme?company_id={$other->id}")
            ->assertOk()
            ->assertJsonPath('data.primary_hex', '#B08D46');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson("/api/v1/company-theme?company_id={$this->genesenn->id}")
            ->assertUnauthorized();
    }

    public function test_applying_a_preset_and_re_reading_shows_the_new_colours(): void
    {
        /*
         * The report, end to end, through the two endpoints the screen
         * actually calls in that order.
         */
        $superAdmin = User::factory()->superAdmin()->create();

        $preset = ThemePreset::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => null,
            'name' => 'Live to 100 Club',
            'is_system' => false,
            'colors' => ['primary_hex' => '#0B2B5B', 'accent_hex' => '#F59E0B'],
        ]);

        $this->actingAs($superAdmin)
            ->postJson("/api/v1/theme-presets/{$preset->id}/apply", ['company_id' => $this->genesenn->id])
            ->assertOk();

        $this->actingAs($superAdmin)
            ->getJson("/api/v1/company-theme?company_id={$this->genesenn->id}")
            ->assertOk()
            ->assertJsonPath('data.primary_hex', '#0B2B5B');
    }
}
