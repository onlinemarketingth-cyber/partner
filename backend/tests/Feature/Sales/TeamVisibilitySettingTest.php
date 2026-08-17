<?php

namespace Tests\Feature\Sales;

use App\Enums\TeamVisibilityLevel;
use App\Models\Company;
use App\Models\TeamVisibilitySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-106 / ADR-024 §5 — the admin CRUD for the team-visibility level.
// Same "always a value, never absent" singleton shape as
// VideoProcessingSettingTest / AnnouncementSettingTest, but with a stricter
// read rule: unlike announcement-settings, show() is NOT Agent-readable
// (a team leader is still role = 'agent' — see the Controller docblock).
//
// Covers the TASK-106 acceptance criteria:
//   - unconfigured company → counts_only  → test_an_unconfigured_company_reads_as_counts_only
//   - an Agent gets 403                   → test_an_agent_cannot_read_or_write_team_visibility_settings
//   - cross-tenant access rejected (BR-6) → test_company_admin_company_id_param_is_ignored_scoped_to_own_company_only
//                                           test_company_a_does_not_see_company_b_settings
class TeamVisibilitySettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unconfigured_company_reads_as_counts_only(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->getJson('/api/v1/team-visibility-settings')
            ->assertOk()
            ->assertJsonPath('data.client_visibility_level', TeamVisibilityLevel::CountsOnly->value)
            ->assertJsonPath('data.is_enabled', true);
    }

    // ADR-024 §5 — the level binds the leader, so the leader must not be
    // able to read it, let alone widen it. A team leader is role = 'agent'.
    public function test_an_agent_cannot_read_or_write_team_visibility_settings(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->getJson('/api/v1/team-visibility-settings')
            ->assertForbidden();

        $this->actingAs($agent)
            ->putJson('/api/v1/team-visibility-settings', [
                'client_visibility_level' => TeamVisibilityLevel::FullFile->value,
                'is_enabled' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('team_visibility_settings', ['company_id' => $company->id]);
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/v1/team-visibility-settings')->assertUnauthorized();
        $this->putJson('/api/v1/team-visibility-settings', [
            'client_visibility_level' => TeamVisibilityLevel::Names->value,
            'is_enabled' => true,
        ])->assertUnauthorized();
    }

    public function test_company_admin_can_configure_and_reread_the_level(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // update() re-fetches via forCompany() (a plain array, never absent),
        // so this responds 200 on first-time create and later update alike.
        $this->actingAs($admin)
            ->putJson('/api/v1/team-visibility-settings', [
                'client_visibility_level' => TeamVisibilityLevel::Names->value,
                'is_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.client_visibility_level', TeamVisibilityLevel::Names->value)
            ->assertJsonPath('data.is_enabled', true);

        $this->actingAs($admin)
            ->getJson('/api/v1/team-visibility-settings')
            ->assertOk()
            ->assertJsonPath('data.client_visibility_level', TeamVisibilityLevel::Names->value);

        $this->assertDatabaseHas('team_visibility_settings', [
            'company_id' => $company->id,
            'client_visibility_level' => TeamVisibilityLevel::Names->value,
        ]);
    }

    public function test_all_three_levels_are_accepted(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        foreach (TeamVisibilityLevel::cases() as $level) {
            $this->actingAs($admin)
                ->putJson('/api/v1/team-visibility-settings', [
                    'client_visibility_level' => $level->value,
                    'is_enabled' => true,
                ])
                ->assertOk()
                ->assertJsonPath('data.client_visibility_level', $level->value);
        }
    }

    public function test_an_unknown_level_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/team-visibility-settings', [
                'client_visibility_level' => 'everything',
                'is_enabled' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_visibility_level');
    }

    public function test_both_fields_are_required(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/team-visibility-settings', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_visibility_level', 'is_enabled']);
    }

    public function test_the_master_switch_can_be_turned_off(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/team-visibility-settings', [
                'client_visibility_level' => TeamVisibilityLevel::FullFile->value,
                'is_enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false);

        $this->assertDatabaseHas('team_visibility_settings', [
            'company_id' => $company->id,
            'is_enabled' => false,
        ]);
    }

    // BR-6/§5 regression lock — identical in shape to the fix already covered
    // by VideoProcessingSettingTest/AnnouncementSettingTest: a Company Admin
    // supplying someone else's company_id must write to their OWN row and
    // leave the other tenant untouched. This is the IDOR that mattered here:
    // full_file discloses PDPA health data.
    public function test_company_admin_company_id_param_is_ignored_scoped_to_own_company_only(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminA)->putJson('/api/v1/team-visibility-settings', [
            'client_visibility_level' => TeamVisibilityLevel::FullFile->value,
            'is_enabled' => true,
            'company_id' => $companyB->id,
        ])->assertOk();

        $this->assertDatabaseHas('team_visibility_settings', [
            'company_id' => $companyA->id,
            'client_visibility_level' => TeamVisibilityLevel::FullFile->value,
        ]);
        $this->assertDatabaseMissing('team_visibility_settings', ['company_id' => $companyB->id]);
    }

    // Same guard on the read side: ?company_id is ignored for a Company Admin.
    public function test_company_a_does_not_see_company_b_settings(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        TeamVisibilitySetting::create([
            'company_id' => $companyB->id,
            'client_visibility_level' => TeamVisibilityLevel::FullFile->value,
            'is_enabled' => true,
        ]);

        $this->actingAs($adminA)
            ->getJson('/api/v1/team-visibility-settings?company_id='.$companyB->id)
            ->assertOk()
            ->assertJsonPath('data.client_visibility_level', TeamVisibilityLevel::CountsOnly->value);
    }

    public function test_super_admin_can_target_a_specific_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->putJson('/api/v1/team-visibility-settings', [
            'company_id' => $companyB->id,
            'client_visibility_level' => TeamVisibilityLevel::Names->value,
            'is_enabled' => true,
        ])->assertOk()->assertJsonPath('data.client_visibility_level', TeamVisibilityLevel::Names->value);

        $this->assertDatabaseHas('team_visibility_settings', [
            'company_id' => $companyB->id,
            'client_visibility_level' => TeamVisibilityLevel::Names->value,
        ]);
        $this->assertDatabaseMissing('team_visibility_settings', ['company_id' => $companyA->id]);

        $this->actingAs($superAdmin)
            ->getJson('/api/v1/team-visibility-settings?company_id='.$companyA->id)
            ->assertOk()
            ->assertJsonPath('data.client_visibility_level', TeamVisibilityLevel::CountsOnly->value);
    }

    public function test_super_admin_must_name_a_company_on_write(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->putJson('/api/v1/team-visibility-settings', [
                'client_visibility_level' => TeamVisibilityLevel::Names->value,
                'is_enabled' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_id');
    }

    // The endpoint is a singleton show/update pair — no create/delete verbs
    // exist, so a second PUT must update the same row rather than duplicate
    // it (the unique company_id index also enforces this at the DB level).
    public function test_repeated_writes_update_one_row(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        foreach ([TeamVisibilityLevel::Names, TeamVisibilityLevel::FullFile, TeamVisibilityLevel::CountsOnly] as $level) {
            $this->actingAs($admin)->putJson('/api/v1/team-visibility-settings', [
                'client_visibility_level' => $level->value,
                'is_enabled' => true,
            ])->assertOk();
        }

        $this->assertSame(1, TeamVisibilitySetting::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }
}
