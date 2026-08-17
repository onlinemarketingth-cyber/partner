<?php

namespace Tests\Feature\Engagement;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-076 (2026-08-02, human request: "ระบบ banner ข่าวสารให้เปิดอย่าง
// น้อย 4 ครั้ง ถึงไม่ขึ้น และสามารถกำหนดได้จาก admin") — same "always a
// value, never absent" shape as VideoProcessingSettingTest, but show()
// is Agent-readable (like AffiliateAttributionSettingTest's TASK-033
// gap-fill) since the Agent Portal needs repeat_count to drive its
// auto-pop logic.
//
// TASK-077 (2026-08-02, human request: "เพิ่มการ setting การแสดง banner
// แบบต่างๆ") extended this same singleton with display_style — every PUT
// below now sends both fields since display_style is `required`.
class AnnouncementSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_view_but_not_update_announcement_settings(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->getJson('/api/v1/announcement-settings')
            ->assertOk()
            ->assertJsonPath('data.repeat_count', 4) // config/announcements.php platform default
            ->assertJsonPath('data.display_style', 'bottom_sheet');

        $this->actingAs($agent)
            ->putJson('/api/v1/announcement-settings', ['repeat_count' => 10, 'display_style' => 'full_screen'])
            ->assertForbidden();
    }

    public function test_agent_sees_platform_default_when_company_has_no_override(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->getJson('/api/v1/announcement-settings')
            ->assertOk()
            ->assertJsonPath('data.repeat_count', config('announcements.default_repeat_count'))
            ->assertJsonPath('data.display_style', config('announcements.default_display_style'));
    }

    public function test_company_admin_can_configure_announcement_settings(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // Same as VideoProcessingSettingTest — update() re-fetches via
        // forCompany() (a plain array, never absent), so this always
        // responds 200, first-time create or later update alike.
        $this->actingAs($admin)
            ->putJson('/api/v1/announcement-settings', ['repeat_count' => 7, 'display_style' => 'centered_card'])
            ->assertOk()
            ->assertJsonPath('data.repeat_count', 7)
            ->assertJsonPath('data.display_style', 'centered_card');

        $this->actingAs($admin)
            ->getJson('/api/v1/announcement-settings')
            ->assertOk()
            ->assertJsonPath('data.repeat_count', 7)
            ->assertJsonPath('data.display_style', 'centered_card');
    }

    public function test_announcement_settings_reject_repeat_count_below_1(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/announcement-settings', ['repeat_count' => 0, 'display_style' => 'bottom_sheet'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('repeat_count');
    }

    public function test_announcement_settings_reject_repeat_count_above_sanity_ceiling(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/announcement-settings', ['repeat_count' => 51, 'display_style' => 'bottom_sheet'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('repeat_count');
    }

    // TASK-077 — human-confirmed via AskUserQuestion (2026-08-02): 4
    // fixed display styles, one global value per company.
    public function test_announcement_settings_accepts_all_4_display_styles(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        foreach (['full_screen', 'bottom_sheet', 'centered_card', 'bottom_strip'] as $style) {
            $this->actingAs($admin)
                ->putJson('/api/v1/announcement-settings', ['repeat_count' => 4, 'display_style' => $style])
                ->assertOk()
                ->assertJsonPath('data.display_style', $style);
        }
    }

    public function test_announcement_settings_reject_an_invalid_display_style(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/announcement-settings', ['repeat_count' => 4, 'display_style' => 'sidebar_popup'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('display_style');
    }

    public function test_announcement_settings_require_display_style(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/announcement-settings', ['repeat_count' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('display_style');
    }

    // BR-6/§5 regression lock — same shape as
    // VideoProcessingSettingTest::test_company_admin_company_id_param_is_ignored_scoped_to_own_company_only().
    public function test_company_admin_company_id_param_is_ignored_scoped_to_own_company_only(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminA)->putJson('/api/v1/announcement-settings', [
            'repeat_count' => 9,
            'display_style' => 'full_screen',
            'company_id' => $companyB->id,
        ])->assertOk();

        $this->assertDatabaseHas('announcement_settings', ['company_id' => $companyA->id, 'repeat_count' => 9, 'display_style' => 'full_screen']);
        $this->assertDatabaseMissing('announcement_settings', ['company_id' => $companyB->id]);
    }

    // Tenant isolation (BR-6) — company B's own configured repeat_count/
    // display_style must never leak into company A's response.
    public function test_company_a_does_not_see_company_b_settings(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminB)->putJson('/api/v1/announcement-settings', [
            'repeat_count' => 20,
            'display_style' => 'bottom_strip',
        ])->assertOk();

        $this->actingAs($agentA)
            ->getJson('/api/v1/announcement-settings')
            ->assertOk()
            ->assertJsonPath('data.repeat_count', config('announcements.default_repeat_count'))
            ->assertJsonPath('data.display_style', config('announcements.default_display_style'));
    }
}
