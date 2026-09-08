<?php

namespace Tests\Feature\Theme;

use App\Models\Company;
use App\Models\CompanyThemeSetting;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\ThemePreset;
use App\Models\User;
use App\Services\Platform\CompanyService;
use App\Services\Theme\ThemePresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 2026-09-08 — the human's follow-up to sharing: "พอมีบริษัทใหม่เราต้องมา
 * ตั้งค่าเอง หรือ Super Admin เลือกได้ให้ใช้ได้ทุกบริษัท".
 *
 * TASK-217 and the promotion work made a palette VISIBLE to every company,
 * including companies that did not exist yet. It did not make any company WEAR
 * it: a brand-new tenant still opened on the platform's own colours until
 * somebody went in and pressed "ใช้ชุดนี้". Available and applied are different
 * facts, and the question above is about the second one.
 *
 * Three properties carry the risk, and each has its own test below:
 *
 *   IT ONLY EVER TOUCHES COMPANIES CREATED AFTERWARDS. Existing tenants have
 *   colours somebody chose on purpose. A feature that reached back and
 *   repainted them would be indistinguishable from a bug, and would be noticed
 *   by the customer rather than by us.
 *
 *   IT IS INVISIBLE UNTIL USED. With no palette starred, company creation must
 *   behave exactly as it did before this existed — same rows, same absence of
 *   a company_theme_settings row.
 *
 *   IT CANNOT HAND ONE TENANT'S PALETTE TO EVERY OTHER. Only a ชุดกลาง may be
 *   starred, and only a Super Admin may star it.
 */
class DefaultPaletteForNewCompaniesTest extends TestCase
{
    use RefreshDatabase;

    private const NAVY = '#0B2B5B';

    private Company $thaiLife;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thaiLife = Company::factory()->create(['name' => 'Thai Life']);
    }

    /** @param  array<string, mixed>  $colors */
    private function preset(?int $companyId, string $name = 'Live to 100 Club', array $colors = []): ThemePreset
    {
        return ThemePreset::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => $companyId,
            'name' => $name,
            'is_system' => false,
            'colors' => $colors + ['primary_hex' => self::NAVY, 'accent_hex' => '#F59E0B'],
        ]);
    }

    private function star(ThemePreset $preset, User $actor, bool $on = true): TestResponse
    {
        return $this->actingAs($actor)->putJson("/api/v1/theme-presets/{$preset->id}", [
            'name' => $preset->name,
            'is_default_for_new_companies' => $on,
        ]);
    }

    private function newCompany(string $slug = 'new-co'): Company
    {
        return app(CompanyService::class)->create(['name' => 'บริษัทใหม่', 'slug' => $slug]);
    }

    private function themeOf(Company $company): ?CompanyThemeSetting
    {
        return CompanyThemeSetting::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->first();
    }

    // ── The feature the human asked for ──────────────────────────────

    public function test_a_new_company_opens_wearing_the_starred_palette(): void
    {
        // The whole request, end to end: star it once, and nobody has to set
        // anything up on the next company.
        $preset = $this->preset(null);
        $this->star($preset, User::factory()->superAdmin()->create())->assertOk();

        $company = $this->newCompany();

        $this->assertSame(self::NAVY, $this->themeOf($company)?->primary_hex);
    }

    public function test_with_nothing_starred_a_new_company_is_provisioned_exactly_as_before(): void
    {
        /*
         * The state every installation is in until a Super Admin picks one.
         * A theme row appearing here would be a behaviour change nobody asked
         * for, and would quietly freeze a company onto whatever colours the
         * platform happened to default to on its creation date.
         */
        $this->preset(null);

        $company = $this->newCompany();

        $this->assertNull($this->themeOf($company));
    }

    public function test_existing_companies_are_left_alone(): void
    {
        // Thai Life chose its own look. Starring a palette for FUTURE tenants
        // must not repaint a tenant that is already trading.
        CompanyThemeSetting::withoutGlobalScopes()->create([
            'company_id' => $this->thaiLife->id,
            'primary_hex' => '#123456',
        ]);

        $this->star($this->preset(null), User::factory()->superAdmin()->create())->assertOk();

        $this->assertSame('#123456', $this->themeOf($this->thaiLife)?->primary_hex);
    }

    public function test_the_restore_point_records_the_look_the_company_actually_started_with(): void
    {
        /*
         * "ค่าเริ่มต้น" is a SNAPSHOT offered as the palette a nervous admin
         * can always get back to. If it were taken before the starred palette
         * were applied it would record a look this company never had — and
         * pressing it would be the one action on the screen that changes the
         * colours while claiming to restore them.
         */
        $this->star($this->preset(null), User::factory()->superAdmin()->create())->assertOk();

        $company = $this->newCompany();

        $restorePoint = ThemePreset::withoutGlobalScope(SharedOrTenantScope::class)
            ->where('company_id', $company->id)
            ->where('key', ThemePresetService::DEFAULT_PRESET_KEY)
            ->firstOrFail();

        $this->assertSame(self::NAVY, $restorePoint->colors['primary_hex']);
    }

    public function test_the_platforms_own_palettes_are_still_provisioned_alongside(): void
    {
        // Starting on a chosen look must not cost the new tenant the starter
        // palettes it would otherwise have had to experiment with.
        $this->star($this->preset(null), User::factory()->superAdmin()->create())->assertOk();

        $company = $this->newCompany();

        $this->assertCount(
            count(app(ThemePresetService::class)->designedPalettes()) + 1,
            ThemePreset::withoutGlobalScope(SharedOrTenantScope::class)
                ->where('company_id', $company->id)
                ->get(),
        );
    }

    // ── Choosing, changing and clearing the choice ───────────────────

    public function test_starring_a_second_palette_unstars_the_first(): void
    {
        /*
         * "The starting look" is singular. Two rows both claiming it would
         * make a new company's colours depend on row order, which is the kind
         * of answer that is right until the day it is not.
         */
        $superAdmin = User::factory()->superAdmin()->create();
        $first = $this->preset(null, 'ชุดเดิม');
        $second = $this->preset(null, 'ชุดใหม่', ['primary_hex' => '#7C3AED']);

        $this->star($first, $superAdmin)->assertOk();
        $this->star($second, $superAdmin)->assertOk();

        $this->assertFalse($first->refresh()->is_default_for_new_companies);
        $this->assertTrue($second->refresh()->is_default_for_new_companies);
        $this->assertSame('#7C3AED', $this->themeOf($this->newCompany())?->primary_hex);
    }

    public function test_un_starring_sends_new_companies_back_to_the_platform_default(): void
    {
        /*
         * The deliberate contrast with `is_shared`, whose false is accepted and
         * does nothing. Clearing THIS flag loses no information — "new
         * companies start on the platform's colours" is a real state that
         * existed before the feature — so it must really clear.
         */
        $superAdmin = User::factory()->superAdmin()->create();
        $preset = $this->preset(null);

        $this->star($preset, $superAdmin)->assertOk();
        $this->star($preset, $superAdmin, on: false)
            ->assertOk()
            ->assertJsonPath('data.is_default_for_new_companies', false);

        $this->assertNull($this->themeOf($this->newCompany()));
    }

    public function test_a_plain_rename_leaves_the_star_where_it_was(): void
    {
        // Every existing caller sends `name` alone. Omitting the flag has to
        // mean "leave this alone", not "clear it".
        $superAdmin = User::factory()->superAdmin()->create();
        $preset = $this->preset(null);
        $this->star($preset, $superAdmin)->assertOk();

        $this->actingAs($superAdmin)
            ->putJson("/api/v1/theme-presets/{$preset->id}", ['name' => 'ชื่อใหม่'])
            ->assertOk();

        $this->assertTrue($preset->refresh()->is_default_for_new_companies);
    }

    public function test_sharing_and_starring_arrive_in_one_request(): void
    {
        /*
         * The screen's actual flow: the Super Admin is looking at a palette
         * they saved under one company and wants it to be the starting look
         * everywhere. Refusing this would force a promote-then-star round trip
         * whose middle state ("shared but not starred") they never asked for.
         */
        $preset = $this->preset($this->thaiLife->id);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/theme-presets/{$preset->id}", [
                'name' => 'Live to 100 Club',
                'is_shared' => true,
                'is_default_for_new_companies' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_shared', true)
            ->assertJsonPath('data.is_default_for_new_companies', true);

        $this->assertSame(self::NAVY, $this->themeOf($this->newCompany())?->primary_hex);
    }

    // ── Who may choose, and what may be chosen ───────────────────────

    public function test_a_company_owned_palette_cannot_become_the_starting_look(): void
    {
        /*
         * The leak this guard exists for: the palette belongs to one customer,
         * and starring it would put their colours on every tenant created
         * afterwards. Refused rather than silently promoted — giving a palette
         * away to the whole platform is a separate decision, and the only one
         * of the two that cannot be undone.
         */
        $preset = $this->preset($this->thaiLife->id);

        $this->star($preset, User::factory()->superAdmin()->create())
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_default_for_new_companies');

        $this->assertFalse($preset->refresh()->is_default_for_new_companies);
    }

    public function test_a_company_admin_cannot_choose_what_every_new_tenant_looks_like(): void
    {
        /*
         * Stripped, not rejected — they were never shown the control, so a 422
         * about it would answer a question they did not ask. The request
         * therefore succeeds as the plain rename it appears to be, and the
         * flag does not move.
         */
        $preset = $this->preset($this->thaiLife->id);
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);

        $this->star($preset, $actor)->assertOk();

        $this->assertFalse($preset->refresh()->is_default_for_new_companies);
    }

    public function test_the_service_refuses_a_company_admin_even_off_the_http_path(): void
    {
        /*
         * The Form Request guards one route; this guards the method. A console
         * command or a job that set the flag without passing through Gate
         * would otherwise decide the look of every future tenant.
         */
        $preset = $this->preset(null);
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);

        $this->expectException(ValidationException::class);

        app(ThemePresetService::class)->update(
            $preset,
            ['name' => 'x', 'is_default_for_new_companies' => true],
            $actor,
        );
    }

    public function test_a_system_palette_cannot_be_starred(): void
    {
        // Read-only is checked before anything else, and a per-company system
        // palette is not a ชุดกลาง in the first place.
        $preset = ThemePreset::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => $this->thaiLife->id,
            'name' => 'ม่วงพรีเมียม',
            'key' => 'premium_purple',
            'is_system' => true,
            'colors' => ['primary_hex' => '#7C3AED'],
        ]);

        $this->star($preset, User::factory()->superAdmin()->create())->assertStatus(422);

        $this->assertFalse($preset->refresh()->is_default_for_new_companies);
    }

    public function test_a_company_admin_can_see_which_palette_new_companies_start_on(): void
    {
        /*
         * Visible to everyone who can see the row, changeable only by a Super
         * Admin. A Company Admin comparing their look against "the platform's
         * starting look" should not have to guess which row that is.
         */
        $preset = $this->preset(null);
        $this->star($preset, User::factory()->superAdmin()->create())->assertOk();

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]))
            ->getJson('/api/v1/theme-presets')
            ->assertOk()
            ->assertJsonPath('data.0.is_default_for_new_companies', true);
    }

    public function test_deleting_the_starred_palette_takes_the_choice_with_it(): void
    {
        /*
         * Why the flag lives on the preset row rather than in a settings table
         * holding an id: there is no dangling pointer to clean up, and the
         * next company falls back to the platform's colours rather than to a
         * palette that no longer exists.
         */
        $superAdmin = User::factory()->superAdmin()->create();
        $preset = $this->preset(null);
        $this->star($preset, $superAdmin)->assertOk();

        $this->actingAs($superAdmin)
            ->deleteJson("/api/v1/theme-presets/{$preset->id}")
            ->assertNoContent();

        $this->assertNull(app(ThemePresetService::class)->defaultForNewCompanies());
        $this->assertNull($this->themeOf($this->newCompany()));
    }
}
