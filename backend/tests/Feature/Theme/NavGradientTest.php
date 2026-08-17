<?php

namespace Tests\Feature\Theme;

use App\Models\Company;
use App\Models\CompanyThemeSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-161 §3.1 — the nav bar may be a two-stop gradient. New nullable
// `nav_bg_type` / `nav_bg_config` columns mirror the app background's
// existing `background_type` / `background_config` convention; `nav_bg_hex`
// stays the solid value.
//
// The first test is the acceptance criterion that matters most: a row
// written before this feature existed must render EXACTLY as before, with
// no data migration — which is only true if a null type behaves as solid.
class NavGradientTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_row_predating_the_gradient_columns_still_reads_as_a_solid_nav_bar(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // Exactly what an existing row looks like: a solid hex, no type.
        CompanyThemeSetting::create([
            'company_id' => $company->id,
            'nav_bg_hex' => '#123456',
        ]);

        $this->actingAs($admin)->getJson('/api/v1/me/theme')
            ->assertOk()
            ->assertJsonPath('data.nav_bg_hex', '#123456')
            ->assertJsonPath('data.nav_bg_type', null)
            ->assertJsonPath('data.nav_bg_config', null);
    }

    public function test_company_admin_can_save_a_nav_gradient(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->putJson('/api/v1/company-theme', [
            'nav_bg_hex' => '#123456',
            'nav_bg_type' => 'gradient',
            'nav_bg_config' => ['color1' => '#000000', 'color2' => '#ffffff', 'angle' => 90],
        ])->assertOk()
            ->assertJsonPath('data.nav_bg_type', 'gradient')
            ->assertJsonPath('data.nav_bg_config.color1', '#000000')
            ->assertJsonPath('data.nav_bg_config.color2', '#ffffff')
            ->assertJsonPath('data.nav_bg_config.angle', 90)
            // The solid value is kept, not overwritten — it is what a
            // client that ignores the gradient still paints.
            ->assertJsonPath('data.nav_bg_hex', '#123456');
    }

    public function test_a_gradient_missing_a_stop_is_rejected_rather_than_falling_back_to_solid(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->putJson('/api/v1/company-theme', [
            'nav_bg_type' => 'gradient',
            'nav_bg_config' => ['color1' => '#000000', 'angle' => 90],
        ])->assertStatus(422)->assertJsonValidationErrors('nav_bg_config.color2');

        // ...and a gradient with no config at all is equally a 422, not a
        // silently-solid nav bar.
        $this->actingAs($admin)->putJson('/api/v1/company-theme', [
            'nav_bg_type' => 'gradient',
        ])->assertStatus(422)->assertJsonValidationErrors('nav_bg_config');

        $this->assertDatabaseMissing('company_theme_settings', [
            'company_id' => $company->id,
            'nav_bg_type' => 'gradient',
        ]);
    }

    public function test_gradient_stops_must_be_hex_and_the_angle_must_be_within_0_360(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->putJson('/api/v1/company-theme', [
            'nav_bg_type' => 'gradient',
            'nav_bg_config' => ['color1' => 'red', 'color2' => '#ffffff', 'angle' => 90],
        ])->assertStatus(422)->assertJsonValidationErrors('nav_bg_config.color1');

        $this->actingAs($admin)->putJson('/api/v1/company-theme', [
            'nav_bg_type' => 'gradient',
            'nav_bg_config' => ['color1' => '#000000', 'color2' => '#ffffff', 'angle' => 720],
        ])->assertStatus(422)->assertJsonValidationErrors('nav_bg_config.angle');
    }

    public function test_an_unknown_nav_bg_type_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->putJson('/api/v1/company-theme', [
            'nav_bg_type' => 'image',
        ])->assertStatus(422)->assertJsonValidationErrors('nav_bg_type');
    }

    public function test_switching_back_to_solid_keeps_the_solid_hex(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->putJson('/api/v1/company-theme', [
            'nav_bg_hex' => '#123456',
            'nav_bg_type' => 'gradient',
            'nav_bg_config' => ['color1' => '#000000', 'color2' => '#ffffff'],
        ])->assertOk();

        $this->actingAs($admin)->putJson('/api/v1/company-theme', [
            'nav_bg_type' => 'solid',
        ])->assertOk()
            ->assertJsonPath('data.nav_bg_type', 'solid')
            ->assertJsonPath('data.nav_bg_hex', '#123456');
    }

    public function test_the_public_by_slug_theme_also_exposes_the_gradient(): void
    {
        $company = Company::factory()->create();
        CompanyThemeSetting::create([
            'company_id' => $company->id,
            'nav_bg_hex' => '#123456',
            'nav_bg_type' => 'gradient',
            'nav_bg_config' => ['color1' => '#000000', 'color2' => '#ffffff', 'angle' => 45],
        ]);

        // The pre-login/branded pages paint the nav bar too, so the public
        // payload must carry the same two fields (§3.1).
        $this->getJson("/api/v1/public/theme/{$company->slug}")
            ->assertOk()
            ->assertJsonPath('data.nav_bg_type', 'gradient')
            ->assertJsonPath('data.nav_bg_config.angle', 45);
    }
}
