<?php

namespace Tests\Feature\Theme;

use App\Models\Company;
use App\Models\CompanyThemeSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// TASK-055 / ADR-018 — per-company white-label theme: Company Admin sets
// their OWN company's theme, agents may read but not write, the public
// by-slug endpoint exposes only presentational fields without auth, Super
// Admin may set any company, and asset uploads land on the public disk.
class CompanyThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_upsert_and_read_own_theme(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->putJson('/api/v1/company-theme', [
            'primary_hex' => '#123456',
            'accent_hex' => '#abcdef',
            'font_family' => 'Prompt',
            'font_weights' => [400, 700],
            'background_type' => 'gradient',
            'background_config' => ['from' => '#000000', 'to' => '#ffffff', 'angle' => 90],
            'loading_message' => 'โหลดอยู่',
            'label_overrides' => ['app_name' => 'My Brand'],
            // TASK-057 — bottom-nav icon overrides (BR-7 config).
            'nav_icon_overrides' => ['nav_home' => 'star', 'nav_clients' => 'contact'],
        ])->assertOk()->assertJsonPath('data.primary_hex', '#123456');

        $this->assertDatabaseHas('company_theme_settings', [
            'company_id' => $company->id,
            'primary_hex' => '#123456',
            'font_family' => 'Prompt',
        ]);

        $this->actingAs($admin)->getJson('/api/v1/me/theme')
            ->assertOk()
            ->assertJsonPath('data.primary_hex', '#123456')
            ->assertJsonPath('data.accent_hex', '#abcdef')
            ->assertJsonPath('data.font_family', 'Prompt')
            ->assertJsonPath('data.label_overrides.app_name', 'My Brand')
            ->assertJsonPath('data.nav_icon_overrides.nav_home', 'star')
            ->assertJsonPath('data.nav_icon_overrides.nav_clients', 'contact');
    }

    // TASK-057 — nav_icon_overrides values are kept to a plain
    // lowercase/underscore icon-name shape (matching Icon.vue's PATHS
    // keys); reject anything else (script tags, spaces, punctuation)
    // rather than silently storing garbage a client would just fall back
    // from anyway.
    public function test_nav_icon_overrides_rejects_malformed_icon_names(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->putJson('/api/v1/company-theme', [
            'nav_icon_overrides' => ['nav_home' => '<script>alert(1)</script>'],
        ])->assertStatus(422);
    }

    public function test_company_admin_cannot_write_another_companys_theme(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // Admin tries to smuggle the other company's id in the body — it
        // must be ignored and their own company written instead.
        $this->actingAs($admin)->putJson('/api/v1/company-theme', [
            'company_id' => $otherCompany->id,
            'primary_hex' => '#111111',
        ])->assertOk();

        // Own company got the row; the foreign company was never touched.
        $this->assertDatabaseHas('company_theme_settings', [
            'company_id' => $company->id,
            'primary_hex' => '#111111',
        ]);
        $this->assertDatabaseMissing('company_theme_settings', [
            'company_id' => $otherCompany->id,
        ]);
    }

    public function test_agent_cannot_write_theme(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->putJson('/api/v1/company-theme', [
            'primary_hex' => '#222222',
        ])->assertForbidden();
    }

    public function test_public_endpoint_returns_theme_by_slug_without_auth_and_only_presentational_fields(): void
    {
        $company = Company::factory()->create();
        CompanyThemeSetting::create([
            'company_id' => $company->id,
            'primary_hex' => '#654321',
            'font_family' => 'Kanit',
        ]);

        $response = $this->getJson("/api/v1/public/theme/{$company->slug}")
            ->assertOk()
            ->assertJsonPath('data.primary_hex', '#654321')
            ->assertJsonPath('data.company.slug', $company->slug);

        // No sensitive / non-presentational fields leak.
        $response->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.company_id')
            ->assertJsonMissingPath('data.company.id')
            ->assertJsonMissingPath('data.user')
            ->assertJsonMissingPath('data.email');

        // A company with NO theme row still returns neutral defaults (so
        // the endpoint can't be used to probe who has a theme configured).
        $bare = Company::factory()->create();
        $this->getJson("/api/v1/public/theme/{$bare->slug}")
            ->assertOk()
            ->assertJsonPath('data.font_family', 'Kanit')
            ->assertJsonPath('data.company.slug', $bare->slug);
    }

    // TASK-063 — the branded /login?company=<slug> link Admin can copy/QR
    // for agents (fixes "หน้า Login ไม่เปลี่ยนสีตามธีม" — the Agent Portal
    // /login page has no company signal pre-auth, so it needs this hint
    // in the URL). Built server-side from the same FRONTEND_URL config
    // TASK-016's FollowUpReminderNotification already reads, so this
    // isn't a hardcoded BR-7 value.
    public function test_theme_payload_includes_branded_login_link(): void
    {
        $company = Company::factory()->create();
        CompanyThemeSetting::create(['company_id' => $company->id, 'primary_hex' => '#654321']);

        $expected = rtrim((string) config('services.agent_portal.frontend_url'), '/').'/login?company='.$company->slug;

        $this->getJson("/api/v1/public/theme/{$company->slug}")
            ->assertOk()
            ->assertJsonPath('data.login_link', $expected);
    }

    public function test_super_admin_can_set_any_companys_theme(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create(['company_id' => null]);

        $this->actingAs($superAdmin)->putJson('/api/v1/company-theme', [
            'company_id' => $company->id,
            'primary_hex' => '#0f0f0f',
        ])->assertOk()->assertJsonPath('data.primary_hex', '#0f0f0f');

        $this->assertDatabaseHas('company_theme_settings', [
            'company_id' => $company->id,
            'primary_hex' => '#0f0f0f',
        ]);
    }

    public function test_upload_asset_stores_file_on_public_disk_and_saves_path(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/company-theme/asset', [
            'slot' => 'nav',
            'file' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk()
            ->assertJsonPath('data.logos.nav_url', fn ($url) => is_string($url) && $url !== '');

        $theme = CompanyThemeSetting::where('company_id', $company->id)->first();
        $this->assertNotNull($theme->logo_nav_path);
        Storage::disk('public')->assertExists($theme->logo_nav_path);
    }

    public function test_upload_asset_rejects_invalid_slot(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/company-theme/asset', [
            'slot' => 'bogus',
            'file' => UploadedFile::fake()->image('logo.png'),
        ])->assertStatus(422);
    }
}
