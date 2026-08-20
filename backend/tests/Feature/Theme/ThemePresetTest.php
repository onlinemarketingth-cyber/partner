<?php

namespace Tests\Feature\Theme;

use App\Models\Company;
use App\Models\CompanyThemeSetting;
use App\Models\ThemePreset;
use App\Models\User;
use App\Services\Platform\CompanyService;
use App\Services\Theme\ThemePresetService;
use App\Services\Theme\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// TASK-161 §3.2 — company colour presets.
//
// There is no ThemePresetFactory (none of the sibling settings models have
// one either), so rows are created with ThemePreset::create([...]) — the
// same style CertTierTargetModeTest uses where a factory is missing.
//
// The tenant-isolation block below deliberately covers LIST, APPLY, RENAME
// and DELETE. A read-only isolation test is exactly the one that passes
// while `apply` leaks — so each write verb is asserted to leave the victim
// company's data untouched, not merely to return a non-200.
class ThemePresetTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> A full, distinctive colour surface. */
    private const COLOURS = [
        'primary_hex' => '#111111',
        'accent_hex' => '#222222',
        'nav_bg_hex' => '#333333',
        'nav_bg_type' => 'gradient',
        'nav_text_hex' => '#444444',
        'nav_active_hex' => '#555555',
        'card_bg_hex' => '#666666',
        'card_text_hex' => '#777777',
        'card_border_hex' => '#888888',
        'card_shadow' => 'lg',
        'background_type' => 'solid',
    ];

    private function themeFor(Company $company): CompanyThemeSetting
    {
        return CompanyThemeSetting::create(self::COLOURS + [
            'company_id' => $company->id,
            'nav_bg_config' => ['color1' => '#000000', 'color2' => '#ffffff', 'angle' => 90],
            'background_config' => ['color' => '#999999'],
            // Non-colour fields — a preset must never carry these.
            'font_family' => 'Prompt',
            'logo_nav_path' => 'themes/1/nav.png',
            'label_overrides' => ['app_name' => 'My Brand'],
        ]);
    }

    // --- TASK-217: shared presets (ชุดกลาง, company_id = NULL) ---------
    //
    // The rule this block pins down: a shared preset is READABLE and
    // APPLICABLE by every company, and WRITABLE by nobody but a Super
    // Admin. The dangerous half is the second one — a feature that lets
    // one tenant edit a palette every other tenant is using looks
    // identical to a working one until somebody renames it.

    private function sharedPreset(string $name = 'ชุดกลางบริษัท'): ThemePreset
    {
        return ThemePreset::create([
            'company_id' => null,
            'name' => $name,
            'colors' => self::COLOURS,
            'created_by' => null,
        ]);
    }

    public function test_a_super_admin_can_save_a_preset_as_shared(): void
    {
        $company = Company::factory()->create();
        $this->themeFor($company);
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->postJson('/api/v1/theme-presets', [
            'name' => 'โทนกลางแพลตฟอร์ม',
            'company_id' => $company->id,
            'is_shared' => true,
        ])->assertCreated()
            ->assertJsonPath('data.company_id', null)
            ->assertJsonPath('data.is_shared', true)
            // The COLOURS still come from the named company — a shared
            // preset is a snapshot of something, not an empty row.
            ->assertJsonPath('data.colors.primary_hex', '#111111');

        $this->assertDatabaseHas('theme_presets', ['name' => 'โทนกลางแพลตฟอร์ม', 'company_id' => null]);
    }

    /**
     * The whole point of the feature: company B applies a palette that has
     * no owner, and it lands on B.
     */
    public function test_every_company_sees_and_can_apply_a_shared_preset(): void
    {
        $shared = $this->sharedPreset();

        foreach ([Company::factory()->create(), Company::factory()->create()] as $company) {
            $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

            $this->actingAs($admin)->getJson('/api/v1/theme-presets')
                ->assertOk()
                ->assertJsonFragment(['id' => $shared->id, 'is_shared' => true]);

            $this->actingAs($admin)->postJson("/api/v1/theme-presets/{$shared->id}/apply")
                ->assertOk();

            $this->assertDatabaseHas('company_theme_settings', [
                'company_id' => $company->id,
                'primary_hex' => '#111111',
                'card_bg_hex' => '#666666',
            ]);
        }
    }

    /**
     * Applying a shared preset must not invent a theme row for company
     * NULL, and must not touch any company other than the caller's.
     */
    public function test_applying_a_shared_preset_writes_only_the_callers_company(): void
    {
        $mine = Company::factory()->create();
        $other = Company::factory()->create();
        $this->themeFor($other);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $mine->id]);

        $shared = ThemePreset::create([
            'company_id' => null,
            'name' => 'กลาง',
            'colors' => ['primary_hex' => '#abcdef'] + self::COLOURS,
            'created_by' => null,
        ]);

        $this->actingAs($admin)->postJson("/api/v1/theme-presets/{$shared->id}/apply")->assertOk();

        $this->assertDatabaseHas('company_theme_settings', ['company_id' => $mine->id, 'primary_hex' => '#abcdef']);
        $this->assertDatabaseMissing('company_theme_settings', ['company_id' => null]);
        // The bystander company keeps the colours it had.
        $this->assertDatabaseHas('company_theme_settings', ['company_id' => $other->id, 'primary_hex' => '#111111']);
    }

    /** THE test of this task. A tenant must not be able to edit a platform palette. */
    public function test_a_company_admin_cannot_rename_or_delete_a_shared_preset(): void
    {
        $shared = $this->sharedPreset('ห้ามแก้');
        $admin = User::factory()->companyAdmin()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/theme-presets/{$shared->id}", ['name' => 'โดนแก้แล้ว'])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/theme-presets/{$shared->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('theme_presets', ['id' => $shared->id, 'name' => 'ห้ามแก้']);
    }

    public function test_a_super_admin_can_rename_and_delete_a_shared_preset(): void
    {
        $shared = $this->sharedPreset();
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)
            ->putJson("/api/v1/theme-presets/{$shared->id}", ['name' => 'ชื่อใหม่'])
            ->assertOk()
            ->assertJsonPath('data.name', 'ชื่อใหม่');

        $this->actingAs($super)->deleteJson("/api/v1/theme-presets/{$shared->id}")->assertNoContent();
        $this->assertDatabaseMissing('theme_presets', ['id' => $shared->id]);
    }

    /**
     * `is_shared` is a Super-Admin-only parameter. A Company Admin who
     * sends it must get an ordinary company-scoped preset — NOT a 422, and
     * above all not a shared one.
     */
    public function test_a_company_admins_is_shared_flag_is_ignored(): void
    {
        $company = Company::factory()->create();
        $this->themeFor($company);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/theme-presets', [
            'name' => 'ของฉันเอง',
            'is_shared' => true,
        ])->assertCreated()
            ->assertJsonPath('data.company_id', $company->id)
            ->assertJsonPath('data.is_shared', false);

        $this->assertDatabaseMissing('theme_presets', ['name' => 'ของฉันเอง', 'company_id' => null]);
    }

    /**
     * Widening the list must not have widened it too far: adding the
     * shared rows must not drag another TENANT's presets in with them.
     */
    public function test_the_list_adds_shared_presets_without_leaking_another_companys(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();
        $shared = $this->sharedPreset();

        $ours = ThemePreset::create(['company_id' => $mine->id, 'name' => 'ของเรา', 'colors' => self::COLOURS]);
        $hers = ThemePreset::create(['company_id' => $theirs->id, 'name' => 'ของเขา', 'colors' => self::COLOURS]);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $mine->id]);

        $ids = collect($this->actingAs($admin)->getJson('/api/v1/theme-presets')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($shared->id));
        $this->assertTrue($ids->contains($ours->id));
        $this->assertFalse($ids->contains($hers->id), 'another company\'s preset leaked into the list');
    }

    /** Same question for a SUPER ADMIN, whom the global scope does not constrain. */
    public function test_a_super_admin_list_is_still_one_company_plus_the_shared_ones(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $shared = $this->sharedPreset();
        $ofA = ThemePreset::create(['company_id' => $a->id, 'name' => 'A', 'colors' => self::COLOURS]);
        $ofB = ThemePreset::create(['company_id' => $b->id, 'name' => 'B', 'colors' => self::COLOURS]);

        $super = User::factory()->superAdmin()->create();

        $ids = collect($this->actingAs($super)->getJson("/api/v1/theme-presets?company_id={$a->id}")
            ->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($shared->id));
        $this->assertTrue($ids->contains($ofA->id));
        $this->assertFalse($ids->contains($ofB->id));
    }

    /** An Agent gets nothing here, shared or not. */
    public function test_an_agent_still_sees_no_shared_preset(): void
    {
        $shared = $this->sharedPreset();
        $agent = User::factory()->agent()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $this->actingAs($agent)->getJson('/api/v1/theme-presets')->assertForbidden();
        $this->actingAs($agent)->postJson("/api/v1/theme-presets/{$shared->id}/apply")->assertForbidden();
    }

    /**
     * The SERVICE half of the guard, reached without any Gate — the shape a
     * console command or job would take.
     */
    public function test_the_service_refuses_a_company_admin_changing_a_shared_preset_even_without_a_gate(): void
    {
        $shared = $this->sharedPreset();
        $admin = User::factory()->companyAdmin()->create([
            'company_id' => Company::factory()->create()->id,
        ]);
        $service = app(ThemePresetService::class);

        $this->expectException(ValidationException::class);
        $service->rename($shared, 'เปลี่ยนสิ', $admin);
    }

    // --- Snapshot -----------------------------------------------------

    public function test_saving_a_preset_reads_the_companys_current_colours_server_side(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->themeFor($company);

        $response = $this->actingAs($admin)->postJson('/api/v1/theme-presets', [
            'name' => 'โทนปัจจุบัน',
            // A client-supplied colour blob must be IGNORED (§3.2) — the
            // server reads company_theme_settings, it does not trust this.
            'colors' => ['primary_hex' => '#deadbe'],
            'company_id' => 999,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'โทนปัจจุบัน')
            ->assertJsonPath('data.company_id', $company->id)
            ->assertJsonPath('data.colors.primary_hex', '#111111')
            ->assertJsonPath('data.colors.nav_bg_type', 'gradient')
            ->assertJsonPath('data.colors.nav_bg_config.angle', 90)
            ->assertJsonPath('data.created_by', $admin->id);

        $preset = ThemePreset::findOrFail($response->json('data.id'));

        // Every colour field, and ONLY colour fields.
        $this->assertSame(ThemePresetService::COLOR_FIELDS, array_keys($preset->colors));
        foreach (['font_family', 'logo_nav_path', 'label_overrides', 'background_image_path', 'nav_icon_overrides', 'recommended_slot_count'] as $excluded) {
            $this->assertArrayNotHasKey($excluded, $preset->colors);
        }
    }

    public function test_a_company_with_no_theme_row_snapshots_the_resolved_defaults(): void
    {
        // Renamed from ..._snapshots_an_all_null_preset, and the assertion
        // inverted. ag-lead, closing the inconsistency ag-dev flagged: the
        // auto-provisioned "ค่าเริ่มต้น" preset stored RESOLVED colours while
        // this manual save stored the raw nullable columns, so an untouched
        // company got a preset whose swatches rendered blank.
        //
        // Two write paths storing different things under the same word is
        // drift. "บันทึกสีปัจจุบัน" means save what is on screen, and what is
        // on screen for an untouched company is the resolved defaults.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->postJson('/api/v1/theme-presets', ['name' => 'ค่าว่าง'])
            ->assertCreated();

        $preset = ThemePreset::findOrFail($response->json('data.id'));
        $this->assertSame(ThemePresetService::COLOR_FIELDS, array_keys($preset->colors));
        $this->assertNotNull($preset->colors['primary_hex']);
    }

    public function test_a_preset_requires_a_name(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/theme-presets', [])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    // --- Apply --------------------------------------------------------

    public function test_applying_a_preset_restores_every_colour_and_leaves_non_colour_fields_alone(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->themeFor($company);

        $presetId = $this->actingAs($admin)
            ->postJson('/api/v1/theme-presets', ['name' => 'ก่อนแก้'])
            ->assertCreated()->json('data.id');

        // Wreck every colour, and change a non-colour field too.
        $this->actingAs($admin)->putJson('/api/v1/company-theme', [
            'primary_hex' => '#abcdef',
            'card_shadow' => 'none',
            'nav_bg_type' => 'solid',
            'font_family' => 'Sarabun',
        ])->assertOk();

        $this->actingAs($admin)->postJson("/api/v1/theme-presets/{$presetId}/apply")
            ->assertOk()
            ->assertJsonPath('data.primary_hex', '#111111')
            ->assertJsonPath('data.card_shadow', 'lg')
            ->assertJsonPath('data.nav_bg_type', 'gradient')
            ->assertJsonPath('data.nav_bg_config.color2', '#ffffff')
            // NOT part of the preset — the company's identity survives.
            ->assertJsonPath('data.font_family', 'Sarabun');

        $theme = CompanyThemeSetting::where('company_id', $company->id)->firstOrFail();
        $this->assertSame('themes/1/nav.png', $theme->logo_nav_path);
        $this->assertSame(['app_name' => 'My Brand'], $theme->label_overrides);
    }

    public function test_apply_runs_inside_a_transaction(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->themeFor($company);

        $preset = ThemePreset::create([
            'company_id' => $company->id,
            'name' => 'ชุดทดสอบ',
            'colors' => ['primary_hex' => '#0f0f0f'],
        ]);

        // RefreshDatabase itself wraps each test in a transaction, so the
        // baseline level is recorded first and the write must be STRICTLY
        // deeper than it — that is what proves apply() opened its own.
        $baseline = DB::transactionLevel();
        $observed = [];
        CompanyThemeSetting::saved(function () use (&$observed) {
            $observed[] = DB::transactionLevel();
        });

        app(ThemePresetService::class)->apply($preset, $company->id);

        $this->assertNotEmpty($observed, 'apply() did not write the theme row');
        $this->assertGreaterThan($baseline, $observed[0]);
    }

    public function test_applying_a_preset_can_only_ever_target_its_own_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->themeFor($companyA);
        $this->themeFor($companyB);

        $preset = ThemePreset::create([
            'company_id' => $companyA->id,
            'name' => 'A only',
            'colors' => ['primary_hex' => '#0f0f0f'],
        ]);

        // TASK-161 §5.2: a Super Admin must NAME the company they are acting
        // in — they are exempt from TenantScope, so nothing else would catch
        // a mistyped id. This used to be an unqualified POST.
        $superAdmin = User::factory()->superAdmin()->create(['company_id' => null]);
        $this->actingAs($superAdmin)
            ->postJson("/api/v1/theme-presets/{$preset->id}/apply", ['company_id' => $companyA->id])
            ->assertOk();

        $this->assertSame('#0f0f0f', CompanyThemeSetting::withoutGlobalScopes()
            ->where('company_id', $companyA->id)->value('primary_hex'));
        // B is untouched even though a Super Admin ran the apply.
        $this->assertSame('#111111', CompanyThemeSetting::withoutGlobalScopes()
            ->where('company_id', $companyB->id)->value('primary_hex'));
    }

    // --- Rename / delete ----------------------------------------------

    public function test_company_admin_can_rename_and_delete_their_own_preset(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $preset = ThemePreset::create(['company_id' => $company->id, 'name' => 'เดิม', 'colors' => []]);

        $this->actingAs($admin)->putJson("/api/v1/theme-presets/{$preset->id}", ['name' => 'ใหม่'])
            ->assertOk()->assertJsonPath('data.name', 'ใหม่');

        $this->actingAs($admin)->deleteJson("/api/v1/theme-presets/{$preset->id}")->assertNoContent();
        $this->assertDatabaseMissing('theme_presets', ['id' => $preset->id]);
    }

    // --- Tenant isolation: list, apply, rename AND delete (BR-6) -------

    public function test_a_company_cannot_list_apply_rename_or_delete_another_companys_preset(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->themeFor($companyA);
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);

        $presetA = ThemePreset::create([
            'company_id' => $companyA->id,
            'name' => 'A preset',
            'colors' => ['primary_hex' => '#0f0f0f'],
        ]);

        // LIST — A's preset is not in B's list at all.
        $ids = collect($this->actingAs($adminB)->getJson('/api/v1/theme-presets')->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertNotContains($presetA->id, $ids);

        // APPLY — the verb that a read-only isolation test would miss.
        $this->actingAs($adminB)->postJson("/api/v1/theme-presets/{$presetA->id}/apply")
            ->assertNotFound();

        // RENAME
        $this->actingAs($adminB)->putJson("/api/v1/theme-presets/{$presetA->id}", ['name' => 'hijacked'])
            ->assertNotFound();

        // DELETE
        $this->actingAs($adminB)->deleteJson("/api/v1/theme-presets/{$presetA->id}")
            ->assertNotFound();

        // Nothing of A's changed: the preset still exists under its own
        // name, and neither company's theme was rewritten.
        $this->assertDatabaseHas('theme_presets', ['id' => $presetA->id, 'name' => 'A preset']);
        $this->assertSame('#111111', CompanyThemeSetting::withoutGlobalScopes()
            ->where('company_id', $companyA->id)->value('primary_hex'));
        $this->assertDatabaseMissing('company_theme_settings', ['company_id' => $companyB->id]);
    }

    // --- Role gate ----------------------------------------------------

    public function test_an_agent_is_forbidden_on_every_preset_route(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $preset = ThemePreset::create(['company_id' => $company->id, 'name' => 'ชุดสี', 'colors' => []]);

        $this->actingAs($agent)->getJson('/api/v1/theme-presets')->assertForbidden();
        $this->actingAs($agent)->postJson('/api/v1/theme-presets', ['name' => 'x'])->assertForbidden();
        $this->actingAs($agent)->postJson("/api/v1/theme-presets/{$preset->id}/apply")->assertForbidden();
        $this->actingAs($agent)->putJson("/api/v1/theme-presets/{$preset->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($agent)->deleteJson("/api/v1/theme-presets/{$preset->id}")->assertForbidden();

        $this->assertDatabaseHas('theme_presets', ['id' => $preset->id, 'name' => 'ชุดสี']);
    }

    public function test_preset_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/theme-presets')->assertUnauthorized();
        $this->postJson('/api/v1/theme-presets', ['name' => 'x'])->assertUnauthorized();
    }

    // --- §5.1 provisioning: every company gets "ค่าเริ่มต้น" ------------

    /**
     * TASK-164 §3 — a new company now gets SIX system presets: its
     * "ค่าเริ่มต้น" restore point plus the five designed palettes the human
     * supplied. This test used to assert exactly one; the count is asserted
     * against config rather than the literal 6 so adding a sixth palette is
     * a config change and not a test edit.
     */
    public function test_a_new_company_is_provisioned_with_the_default_preset_and_every_designed_palette(): void
    {
        $company = app(CompanyService::class)->create(['name' => 'บริษัทใหม่', 'slug' => 'new-co']);

        $presets = ThemePreset::withoutGlobalScopes()->where('company_id', $company->id)->get();
        $palettes = app(ThemePresetService::class)->designedPalettes();

        $this->assertCount(1 + count($palettes), $presets);
        $this->assertCount(6, $presets, 'The human specified five palettes plus the default snapshot.');

        // Every one of them is a system preset — none is deletable.
        $this->assertTrue($presets->every(fn (ThemePreset $p) => $p->is_system === true));

        $byKey = $presets->keyBy('key');
        $this->assertSame(
            ThemePresetService::DEFAULT_PRESET_NAME,
            $byKey[ThemePresetService::DEFAULT_PRESET_KEY]->name,
        );

        foreach ($palettes as $palette) {
            $this->assertArrayHasKey($palette['key'], $byKey, "palette {$palette['key']} was not seeded");
            $this->assertSame($palette['name'], $byKey[$palette['key']]->name);
            // BR-7: the stored colours are the config's, verbatim — nothing
            // is normalised, defaulted or "improved" on the way in.
            $this->assertSame($palette['colors'], $byKey[$palette['key']]->colors);
        }
    }

    /**
     * TASK-164 §3 — the palettes are config (BR-7), and their shape has to
     * match the colour surface exactly. A key that is missing (or misspelt)
     * would seed a preset that silently applies nothing for that field, so
     * the config file is asserted rather than trusted.
     */
    public function test_every_designed_palette_covers_exactly_the_colour_surface(): void
    {
        $palettes = app(ThemePresetService::class)->designedPalettes();
        $this->assertNotEmpty($palettes);

        foreach ($palettes as $palette) {
            $this->assertSame(
                ThemePresetService::COLOR_FIELDS,
                array_keys($palette['colors']),
                "palette {$palette['key']} does not match COLOR_FIELDS",
            );
            $this->assertContains($palette['colors']['card_shadow'], ['', 'none', 'sm', 'md', 'lg', 'xl']);
        }
    }

    // --- §1 system presets are read-only -------------------------------

    /** The five designed palettes and the restore point are all applicable. */
    public function test_a_system_preset_can_be_applied(): void
    {
        $company = app(CompanyService::class)->create(['name' => 'บริษัทใหม่', 'slug' => 'new-co']);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $gold = ThemePreset::withoutGlobalScopes()
            ->where('company_id', $company->id)->where('key', 'gold_classic')->firstOrFail();

        $this->actingAs($admin)->postJson("/api/v1/theme-presets/{$gold->id}/apply")
            ->assertOk()
            ->assertJsonPath('data.primary_hex', $gold->colors['primary_hex'])
            ->assertJsonPath('data.card_shadow', $gold->colors['card_shadow'])
            // ThemeResource nests the app background (`background.type/config`).
            ->assertJsonPath('data.background.type', 'gradient')
            ->assertJsonPath('data.background.config.angle', 160);

        $this->assertSame(
            $gold->colors['nav_text_hex'],
            CompanyThemeSetting::withoutGlobalScopes()->where('company_id', $company->id)->value('nav_text_hex'),
        );
    }

    /**
     * §1 — 422, NOT 403: the row exists and the admin may see and apply it,
     * they simply may not change it. A 403 would read as "this is not
     * yours", which is the wrong thing to tell them.
     */
    public function test_a_system_preset_cannot_be_renamed_or_deleted(): void
    {
        $company = app(CompanyService::class)->create(['name' => 'บริษัทใหม่', 'slug' => 'new-co']);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $systemPresets = ThemePreset::withoutGlobalScopes()
            ->where('company_id', $company->id)->where('is_system', true)->get();
        $this->assertCount(6, $systemPresets);

        foreach ($systemPresets as $preset) {
            $this->actingAs($admin)
                ->putJson("/api/v1/theme-presets/{$preset->id}", ['name' => 'ของฉันแล้ว'])
                ->assertStatus(422)
                // The admin must be TOLD why, in Thai — a bare 422 reads as
                // a broken form.
                ->assertJsonPath('message', ThemePresetService::SYSTEM_PRESET_READ_ONLY_MESSAGE);

            $this->actingAs($admin)
                ->deleteJson("/api/v1/theme-presets/{$preset->id}")
                ->assertStatus(422)
                ->assertJsonPath('message', ThemePresetService::SYSTEM_PRESET_READ_ONLY_MESSAGE);
        }

        // Nothing was renamed and nothing was removed.
        $after = ThemePreset::withoutGlobalScopes()->where('company_id', $company->id)->get();
        $this->assertCount(6, $after);
        $this->assertSame(
            $systemPresets->pluck('name')->sort()->values()->all(),
            $after->pluck('name')->sort()->values()->all(),
        );
    }

    /**
     * §1 — the SERVICE half. The Policy guards a route; this guards the
     * method, so a console command, job or seeder that never passes through
     * a Gate cannot wipe out a company's restore point either (the same
     * reasoning as ModuleOrderService's second check, TASK-151).
     */
    public function test_the_service_refuses_to_rename_or_delete_a_system_preset_even_without_a_gate(): void
    {
        $company = Company::factory()->create();
        $service = app(ThemePresetService::class);
        $preset = $service->provisionDefault($company);

        $this->assertTrue($preset->is_system);

        try {
            $service->rename($preset, 'เปลี่ยนแล้ว');
            $this->fail('rename() accepted a system preset');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('preset', $e->errors());
        }

        try {
            $service->delete($preset);
            $this->fail('delete() accepted a system preset');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('preset', $e->errors());
        }

        $this->assertDatabaseHas('theme_presets', [
            'id' => $preset->id,
            'name' => ThemePresetService::DEFAULT_PRESET_NAME,
        ]);
    }

    /** §1 — a preset the admin saved themselves keeps today's behaviour exactly. */
    public function test_a_user_saved_preset_can_still_be_renamed_and_deleted(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->themeFor($company);

        $presetId = $this->actingAs($admin)
            ->postJson('/api/v1/theme-presets', ['name' => 'ของฉัน'])
            ->assertCreated()
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.key', null)
            ->json('data.id');

        $this->actingAs($admin)->putJson("/api/v1/theme-presets/{$presetId}", ['name' => 'ของฉันใหม่'])
            ->assertOk()->assertJsonPath('data.name', 'ของฉันใหม่');

        $this->actingAs($admin)->deleteJson("/api/v1/theme-presets/{$presetId}")->assertNoContent();
        $this->assertDatabaseMissing('theme_presets', ['id' => $presetId]);
    }

    /** §1 — the UI needs the flag to know which controls to hide. */
    public function test_the_resource_exposes_is_system(): void
    {
        $company = app(CompanyService::class)->create(['name' => 'บริษัทใหม่', 'slug' => 'new-co']);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $rows = $this->actingAs($admin)->getJson('/api/v1/theme-presets')->assertOk()->json('data');

        $this->assertCount(6, $rows);
        foreach ($rows as $row) {
            // Strictly true, not 1 — the Admin screen tests `=== true`.
            $this->assertTrue($row['is_system'], "{$row['name']} is not flagged as a system preset");
            $this->assertNotNull($row['key']);
        }
    }

    // --- §3 seeding is idempotent on `key` -----------------------------

    public function test_seeding_the_designed_palettes_is_idempotent_on_key(): void
    {
        $company = Company::factory()->create();
        $service = app(ThemePresetService::class);

        $service->provisionSystemPresets($company);
        $service->provisionSystemPresets($company);
        $service->provisionSystemPresets($company);

        $presets = ThemePreset::withoutGlobalScopes()->where('company_id', $company->id)->get();

        $this->assertCount(6, $presets);
        $this->assertSame(6, $presets->pluck('key')->unique()->count());
    }

    /**
     * §3 — re-running the MIGRATION itself, not merely the Service twice.
     * Re-running a migration is the scenario the idempotency claim is about.
     */
    public function test_the_designed_palette_backfill_migration_is_idempotent(): void
    {
        $company = Company::factory()->create();

        $migration = require database_path('migrations/2026_08_28_090100_seed_system_theme_presets_for_existing_companies.php');

        $migration->up();
        $migration->up();

        $presets = ThemePreset::withoutGlobalScopes()->where('company_id', $company->id)->get();
        $this->assertCount(6, $presets);
        $this->assertSame(6, $presets->pluck('key')->unique()->count());
    }

    /**
     * §2 — a "ค่าเริ่มต้น" row created before TASK-164 (no key, no flag) is
     * UPGRADED in place, not re-created: it is the company's restore point,
     * so whatever colours it holds must survive becoming read-only.
     */
    public function test_the_backfill_upgrades_a_legacy_default_preset_to_a_system_preset(): void
    {
        $company = Company::factory()->create();

        // Exactly the shape TASK-161 §5.1 left behind: named, unkeyed, not
        // flagged. forceFill because `key`/`is_system` are what we are
        // pretending do not exist yet.
        $legacy = ThemePreset::create([
            'company_id' => $company->id,
            'name' => ThemePresetService::DEFAULT_PRESET_NAME,
            'colors' => ['primary_hex' => '#0f0f0f'],
        ]);
        $legacy->forceFill(['key' => null, 'is_system' => false])->save();

        $migration = require database_path('migrations/2026_08_28_090100_seed_system_theme_presets_for_existing_companies.php');
        $migration->up();

        $upgraded = $legacy->fresh();
        $this->assertTrue($upgraded->is_system);
        $this->assertSame(ThemePresetService::DEFAULT_PRESET_KEY, $upgraded->key);
        // Its colours are untouched — not overwritten with a fresh snapshot.
        $this->assertSame('#0f0f0f', $upgraded->colors['primary_hex']);

        // And it is not duplicated: still one restore point, plus the five.
        $this->assertCount(6, ThemePreset::withoutGlobalScopes()->where('company_id', $company->id)->get());
    }

    /**
     * §2 — the SCHEMA migration's own in-place upgrade of pre-existing rows,
     * which is what runs against a real database (the §3 backfill above only
     * covers the path that also seeds palettes).
     */
    public function test_the_schema_migration_flags_existing_default_presets(): void
    {
        $company = Company::factory()->create();
        $preset = ThemePreset::create([
            'company_id' => $company->id,
            'name' => ThemePresetService::DEFAULT_PRESET_NAME,
            'colors' => [],
        ]);
        $preset->forceFill(['key' => null, 'is_system' => false])->save();

        $mine = ThemePreset::create([
            'company_id' => $company->id, 'name' => 'ชุดของฉัน', 'colors' => [],
        ]);

        // The migration's own up(). It is guarded on Schema::hasColumn, so
        // re-running it here skips the (already applied) schema half and
        // runs the data half — the part being asserted.
        $migration = require database_path('migrations/2026_08_28_090000_add_system_flag_to_theme_presets_table.php');
        $migration->up();

        $this->assertTrue($preset->fresh()->is_system);
        $this->assertSame(ThemePresetService::DEFAULT_PRESET_KEY, $preset->fresh()->key);
        // A preset the admin saved is NOT swept up by the name match.
        $this->assertFalse($mine->fresh()->is_system);
        $this->assertNull($mine->fresh()->key);
    }

    /**
     * §5.1 — the provisioned preset is a COPY of the theme the company
     * already has, so applying it must change nothing. If this ever fails,
     * the preset is describing a look the company never had.
     */
    public function test_applying_the_provisioned_preset_is_a_no_op_against_the_theme_it_snapshotted(): void
    {
        $company = Company::factory()->create();
        $this->themeFor($company);

        $preset = app(ThemePresetService::class)->provisionDefault($company);

        $before = CompanyThemeSetting::withoutGlobalScopes()
            ->where('company_id', $company->id)->firstOrFail()->only(ThemePresetService::COLOR_FIELDS);

        app(ThemePresetService::class)->apply($preset, $company->id);

        $after = CompanyThemeSetting::withoutGlobalScopes()
            ->where('company_id', $company->id)->firstOrFail()->only(ThemePresetService::COLOR_FIELDS);

        $this->assertSame($before, $after);
    }

    /**
     * §5.1 — "a company with no company_theme_settings row must get a
     * preset whose swatches render and whose apply is deterministic — not a
     * preset of nulls".
     *
     * Only the fields ThemeService::defaults() actually resolves are
     * asserted non-null, and they are compared AGAINST that method rather
     * than against literals: BR-7 — no colour is invented here or in the
     * test, this only proves the preset carries whatever the platform
     * default already is.
     */
    public function test_a_company_with_no_theme_row_is_provisioned_with_resolved_not_null_colours(): void
    {
        $company = Company::factory()->create();
        $this->assertDatabaseMissing('company_theme_settings', ['company_id' => $company->id]);

        $preset = app(ThemePresetService::class)->provisionDefault($company);
        $defaults = app(ThemeService::class)->defaults();

        foreach (['primary_hex', 'accent_hex', 'background_type'] as $field) {
            $this->assertNotNull($preset->colors[$field], "{$field} snapshotted as null");
            $this->assertSame($defaults[$field], $preset->colors[$field]);
        }

        // The shape is still the full colour surface, and still ONLY that —
        // font_family is in defaults() but is identity, not a "look" (§3.2).
        $this->assertSame(ThemePresetService::COLOR_FIELDS, array_keys($preset->colors));
        $this->assertArrayNotHasKey('font_family', $preset->colors);
    }

    /**
     * §5.1 — the backfill for companies that already existed. Runs the
     * migration's OWN up() twice (not just the Service twice): re-running a
     * migration is the scenario the idempotency claim is about.
     */
    public function test_the_backfill_migration_is_idempotent(): void
    {
        // Company::factory() writes the row directly, bypassing
        // CompanyService — i.e. exactly a company that predates §5.1.
        $company = Company::factory()->create();
        $this->assertSame(0, ThemePreset::withoutGlobalScopes()->where('company_id', $company->id)->count());

        $migration = require database_path('migrations/2026_08_27_090000_backfill_default_theme_preset_for_existing_companies.php');

        $migration->up();
        $migration->up();

        $presets = ThemePreset::withoutGlobalScopes()->where('company_id', $company->id)->get();
        $this->assertCount(1, $presets);
        $this->assertSame(ThemePresetService::DEFAULT_PRESET_NAME, $presets->first()->name);
    }

    public function test_the_backfill_leaves_an_existing_default_preset_untouched(): void
    {
        $company = Company::factory()->create();
        $mine = ThemePreset::create([
            'company_id' => $company->id,
            'name' => ThemePresetService::DEFAULT_PRESET_NAME,
            'colors' => ['primary_hex' => '#0f0f0f'],
        ]);

        $migration = require database_path('migrations/2026_08_27_090000_backfill_default_theme_preset_for_existing_companies.php');
        $migration->up();

        $this->assertSame(1, ThemePreset::withoutGlobalScopes()->where('company_id', $company->id)->count());
        // NOT overwritten with the resolved snapshot.
        $this->assertSame('#0f0f0f', $mine->fresh()->colors['primary_hex']);
    }

    // --- §5.2 Super Admin, scoped to one company ----------------------

    public function test_super_admin_lists_and_applies_within_one_named_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->themeFor($companyA);
        $this->themeFor($companyB);

        $presetA = ThemePreset::create([
            'company_id' => $companyA->id, 'name' => 'A preset', 'colors' => ['primary_hex' => '#0a0a0a'],
        ]);
        $presetB = ThemePreset::create([
            'company_id' => $companyB->id, 'name' => 'B preset', 'colors' => ['primary_hex' => '#0b0b0b'],
        ]);

        $superAdmin = User::factory()->superAdmin()->create(['company_id' => null]);

        // LIST is scoped to the named company — NOT every tenant's presets
        // in one undifferentiated pile (TenantScope does not constrain a
        // Super Admin, so this where() is the only thing that scopes it).
        $ids = collect($this->actingAs($superAdmin)
            ->getJson('/api/v1/theme-presets?company_id='.$companyA->id)
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($presetA->id, $ids);
        $this->assertNotContains($presetB->id, $ids);

        // APPLY inside that same company works.
        $this->actingAs($superAdmin)
            ->postJson("/api/v1/theme-presets/{$presetA->id}/apply", ['company_id' => $companyA->id])
            ->assertOk()->assertJsonPath('data.primary_hex', '#0a0a0a');

        // SAVE inside that same company works, and lands on A.
        $this->actingAs($superAdmin)
            ->postJson('/api/v1/theme-presets', ['name' => 'จาก Super Admin', 'company_id' => $companyA->id])
            ->assertCreated()->assertJsonPath('data.company_id', $companyA->id);
    }

    /**
     * §5.2, the whole point of it: "reading a preset from company A and
     * writing settings to company B must not be expressible". A Super
     * Admin bypasses TenantScope, so nothing else would catch this.
     */
    public function test_super_admin_naming_a_different_company_than_the_presets_owner_is_rejected(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->themeFor($companyA);
        $this->themeFor($companyB);

        $presetA = ThemePreset::create([
            'company_id' => $companyA->id, 'name' => 'A preset', 'colors' => ['primary_hex' => '#0a0a0a'],
        ]);

        $superAdmin = User::factory()->superAdmin()->create(['company_id' => null]);

        $this->actingAs($superAdmin)
            ->postJson("/api/v1/theme-presets/{$presetA->id}/apply", ['company_id' => $companyB->id])
            ->assertStatus(422)->assertJsonValidationErrors('company_id');

        // NEITHER company was written: not B (the named one) and not A
        // (which a "the preset's own company wins" fallback would have hit).
        foreach ([$companyA, $companyB] as $company) {
            $this->assertSame('#111111', CompanyThemeSetting::withoutGlobalScopes()
                ->where('company_id', $company->id)->value('primary_hex'));
        }
    }

    public function test_super_admin_must_name_a_company_and_it_must_be_a_real_one(): void
    {
        $company = Company::factory()->create();
        $preset = ThemePreset::create(['company_id' => $company->id, 'name' => 'x', 'colors' => []]);
        $superAdmin = User::factory()->superAdmin()->create(['company_id' => null]);

        // Missing entirely — an unnamed company would mean "every tenant".
        $this->actingAs($superAdmin)->getJson('/api/v1/theme-presets')
            ->assertStatus(422)->assertJsonValidationErrors('company_id');
        $this->actingAs($superAdmin)->postJson('/api/v1/theme-presets', ['name' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('company_id');
        $this->actingAs($superAdmin)->postJson("/api/v1/theme-presets/{$preset->id}/apply")
            ->assertStatus(422)->assertJsonValidationErrors('company_id');

        // Present but not a real company — the mistyped-id case. It must
        // never reach a write, because there is no TenantScope behind it.
        $this->actingAs($superAdmin)->postJson('/api/v1/theme-presets', ['name' => 'x', 'company_id' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors('company_id');
    }

    /**
     * §5.2: "for a Company Admin it is their own and any supplied value is
     * ignored" — ignored, not honoured, and not a 422 about a field that
     * has no effect on their request either.
     */
    public function test_a_company_admins_supplied_company_id_is_ignored(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->themeFor($companyA);
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        $presetB = ThemePreset::create([
            'company_id' => $companyB->id, 'name' => 'B preset', 'colors' => ['primary_hex' => '#0b0b0b'],
        ]);

        // LIST: naming B returns A's list, not B's, and not a 422.
        $ids = collect($this->actingAs($adminA)
            ->getJson('/api/v1/theme-presets?company_id='.$companyB->id)
            ->assertOk()->json('data'))->pluck('id')->all();
        $this->assertNotContains($presetB->id, $ids);

        // SAVE: naming B saves under A. A nonsense id is equally ignored
        // rather than producing a validation error about it.
        $this->actingAs($adminA)
            ->postJson('/api/v1/theme-presets', ['name' => 'ของฉัน', 'company_id' => $companyB->id])
            ->assertCreated()->assertJsonPath('data.company_id', $companyA->id);

        $this->actingAs($adminA)
            ->postJson('/api/v1/theme-presets', ['name' => 'ของฉันอีกชุด', 'company_id' => 999999])
            ->assertCreated()->assertJsonPath('data.company_id', $companyA->id);

        // B gained nothing from any of it.
        $this->assertSame(1, ThemePreset::withoutGlobalScopes()->where('company_id', $companyB->id)->count());
    }
}
