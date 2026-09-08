<?php

namespace Tests\Feature\Theme;

use App\Models\Company;
use App\Models\ThemePreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-08 — promoting a saved palette to ชุดกลาง after the fact.
 *
 * TASK-217 offered the choice only at CREATE time, and the human hit the
 * obvious consequence: they had already saved a palette under one company and
 * wanted every company to have it. The only route was to re-create it by hand
 * under a company they were not looking at. A flag shown once should not
 * become unreachable a second later.
 *
 * Two properties matter more than the feature:
 *
 *   IT IS SUPER-ADMIN-ONLY, at three layers. A Company Admin who sends the
 *   flag has it stripped before validation (they were never shown the control,
 *   so a 422 about it would answer a question they did not ask), and the
 *   Service refuses it again for callers that never pass through a Form
 *   Request at all. Getting this wrong puts one tenant's palette on every
 *   other tenant's screen.
 *
 *   IT IS ONE-WAY, deliberately. `company_id` is the only record of which
 *   company owned the palette, so un-sharing would have to guess an owner —
 *   in practice whichever company the Super Admin is scoped to, which may be a
 *   tenant that never had it. `is_shared: false` is accepted and does nothing
 *   rather than doing something plausible.
 */
class ThemePresetSharingTest extends TestCase
{
    use RefreshDatabase;

    private Company $thaiLife;

    private Company $genesenn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thaiLife = Company::factory()->create(['name' => 'Thai Life']);
        $this->genesenn = Company::factory()->create(['name' => 'GENESENN']);
    }

    private function preset(?int $companyId, bool $system = false): ThemePreset
    {
        return ThemePreset::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'name' => 'Live to 100 Club',
            'is_system' => $system,
            'colors' => ['primary_hex' => '#1e3a8a', 'accent_hex' => '#f59e0b'],
        ]);
    }

    public function test_a_super_admin_promotes_a_saved_palette_to_every_company(): void
    {
        // The report, end to end: saved under Thai Life, wanted everywhere.
        $preset = $this->preset($this->thaiLife->id);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/theme-presets/{$preset->id}", [
                'name' => 'Live to 100 Club',
                'is_shared' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_shared', true);

        $this->assertNull($preset->refresh()->company_id);
    }

    public function test_the_other_company_can_then_see_it(): void
    {
        // The point of sharing, asserted where the user would look: the other
        // company's own list.
        $preset = $this->preset($this->thaiLife->id);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->putJson("/api/v1/theme-presets/{$preset->id}", ['name' => 'Live to 100 Club', 'is_shared' => true])
            ->assertOk();

        $this->actingAs($superAdmin)
            ->getJson("/api/v1/theme-presets?company_id={$this->genesenn->id}")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Live to 100 Club');
    }

    public function test_a_company_admin_cannot_push_their_palette_onto_everybody(): void
    {
        /*
         * The one that would be a platform-wide leak. The flag is STRIPPED
         * rather than rejected — they were never shown the control, so a 422
         * about it would answer a question they did not ask — which means the
         * request succeeds as a plain rename and the ownership does not move.
         */
        $preset = $this->preset($this->thaiLife->id);
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);

        $this->actingAs($actor)
            ->putJson("/api/v1/theme-presets/{$preset->id}", [
                'name' => 'Renamed By Company Admin',
                'is_shared' => true,
            ])
            ->assertOk();

        $preset->refresh();

        $this->assertSame('Renamed By Company Admin', $preset->name);
        $this->assertSame($this->thaiLife->id, $preset->company_id);
    }

    public function test_a_company_admin_still_cannot_touch_an_already_shared_one(): void
    {
        // TASK-217's rule, unchanged: it is in use by every other company and
        // nothing on their screen would tell them so.
        $preset = $this->preset(null);

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]))
            ->putJson("/api/v1/theme-presets/{$preset->id}", ['name' => 'Mine Now'])
            ->assertStatus(422);

        $this->assertSame('Live to 100 Club', $preset->refresh()->name);
    }

    public function test_a_system_palette_cannot_be_promoted_either(): void
    {
        // It is already on every screen, and the rule that it is read-only is
        // checked before anything else.
        $preset = $this->preset($this->thaiLife->id, system: true);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/theme-presets/{$preset->id}", ['name' => 'x', 'is_shared' => true])
            ->assertStatus(422);

        $this->assertSame($this->thaiLife->id, $preset->refresh()->company_id);
    }

    public function test_un_sharing_is_accepted_and_changes_nothing(): void
    {
        /*
         * `company_id` is the only record of who owned it, so there is no
         * honest answer to "back to whom". Doing nothing is better than
         * handing the palette to whichever company the admin happens to be
         * scoped to — which may be one that never had it.
         */
        $preset = $this->preset(null);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/theme-presets/{$preset->id}", [
                'name' => 'Live to 100 Club',
                'is_shared' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_shared', true);

        $this->assertNull($preset->refresh()->company_id);
    }

    public function test_a_plain_rename_still_works_and_moves_nothing(): void
    {
        // Every existing caller sends `name` alone; omitting the flag must
        // mean "leave ownership exactly as it is".
        $preset = $this->preset($this->thaiLife->id);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/theme-presets/{$preset->id}", ['name' => 'Just A Rename'])
            ->assertOk();

        $preset->refresh();

        $this->assertSame('Just A Rename', $preset->name);
        $this->assertSame($this->thaiLife->id, $preset->company_id);
    }
}
