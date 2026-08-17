<?php

namespace Tests\Feature\Engagement;

use App\Models\Announcement;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-080 (2026-08-03, human request: "ผมอยากได้ระบบข่าวสาร สามารถแสดง
// เป็นแบบ banner ได้แบบ Product") — show_as_modal / show_as_banner /
// banner_pages on `announcements`.
//
// The first test here is the backward-compatibility guarantee that makes
// the whole feature safe to ship: every announcement authored before
// TASK-080 (and every client that never sends the new fields) must keep
// behaving exactly as it did — modal on, banner off — which is why the
// columns carry DB defaults instead of being required inputs.
//
// Defaults are asserted through a follow-up GET rather than off the POST
// response: Model::create() returns an instance holding only the
// attributes that were actually assigned, so DB-level defaults are not
// present on it until the row is re-read.
class AnnouncementBannerDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_announcement_without_the_display_fields_keeps_the_pre_task_080_defaults(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->postJson('/api/v1/announcements', [
            'title' => 'ประกาศทั่วไป',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
        ])->assertCreated();

        $announcement = Announcement::find($response->json('data.id'));
        $this->assertTrue($announcement->show_as_modal);
        $this->assertFalse($announcement->show_as_banner);
        $this->assertNull($announcement->banner_pages);

        $this->actingAs($admin)->getJson("/api/v1/announcements/{$announcement->id}")
            ->assertOk()
            ->assertJsonPath('data.show_as_modal', true)
            ->assertJsonPath('data.show_as_banner', false)
            ->assertJsonPath('data.banner_pages', null);
    }

    public function test_company_admin_can_create_a_banner_announcement_with_selected_pages(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->postJson('/api/v1/announcements', [
            'title' => 'แบนเนอร์ข่าวสาร',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
            'show_as_modal' => false,
            'show_as_banner' => true,
            'banner_pages' => ['home', 'products'],
        ])->assertCreated()
            ->assertJsonPath('data.show_as_modal', false)
            ->assertJsonPath('data.show_as_banner', true)
            ->assertJsonPath('data.banner_pages', ['home', 'products']);

        $id = $response->json('data.id');

        $this->actingAs($admin)->getJson("/api/v1/announcements/{$id}")
            ->assertOk()
            ->assertJsonPath('data.show_as_modal', false)
            ->assertJsonPath('data.show_as_banner', true)
            ->assertJsonPath('data.banner_pages', ['home', 'products']);
    }

    public function test_banner_pages_accepts_every_valid_page_value(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/announcements', [
            'title' => 'ทุกหน้า',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
            'show_as_banner' => true,
            'banner_pages' => ['home', 'products', 'announcements'],
        ])->assertCreated()
            ->assertJsonPath('data.banner_pages', ['home', 'products', 'announcements']);
    }

    public function test_an_unknown_banner_page_value_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/announcements', [
            'title' => 'หน้าที่ไม่มีอยู่จริง',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
            'show_as_banner' => true,
            'banner_pages' => ['dashboard'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('banner_pages.0');
    }

    // The Admin form submits as multipart/form-data whenever an image or
    // video is attached, where a boolean can only travel as the string
    // '1'/'0' — same wire shape as the long-standing is_pinned field.
    public function test_boolean_display_flags_survive_a_multipart_form_data_submission(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->post('/api/v1/announcements', [
            'title' => 'ส่งแบบ multipart',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
            'show_as_modal' => '0',
            'show_as_banner' => '1',
            'banner_pages' => ['home'],
        ])->assertCreated();

        $announcement = Announcement::find($response->json('data.id'));
        $this->assertFalse($announcement->show_as_modal);
        $this->assertTrue($announcement->show_as_banner);
        $this->assertSame(['home'], $announcement->banner_pages);
    }

    public function test_turning_the_banner_off_does_not_clear_the_saved_page_selection(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $create = $this->actingAs($admin)->postJson('/api/v1/announcements', [
            'title' => 'เปิดแล้วปิด',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
            'show_as_banner' => true,
            'banner_pages' => ['home', 'products'],
        ])->assertCreated();
        $id = $create->json('data.id');

        $this->actingAs($admin)->putJson("/api/v1/announcements/{$id}", [
            'show_as_banner' => false,
        ])->assertOk()
            ->assertJsonPath('data.show_as_banner', false)
            ->assertJsonPath('data.banner_pages', ['home', 'products']);

        $announcement = Announcement::find($id);
        $this->assertFalse($announcement->show_as_banner);
        $this->assertSame(['home', 'products'], $announcement->banner_pages);
    }

    // BR-6 — a banner announcement is still just an announcement row, so
    // it must obey the same company scoping as every other row in the feed.
    public function test_an_agent_never_receives_another_companys_banner_announcement(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $agentOfA = User::factory()->agent()->create(['company_id' => $companyA->id]);

        $foreignBanner = Announcement::create([
            'company_id' => $companyB->id,
            'title' => 'แบนเนอร์ของอีกบริษัท',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
            'is_pinned' => false,
            'show_as_modal' => false,
            'show_as_banner' => true,
            'banner_pages' => ['home'],
            'published_at' => now()->subMinute(),
            'expires_at' => null,
            'created_by' => null,
        ]);

        $ownBanner = Announcement::create([
            'company_id' => $companyA->id,
            'title' => 'แบนเนอร์ของบริษัทตัวเอง',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
            'is_pinned' => false,
            'show_as_modal' => false,
            'show_as_banner' => true,
            'banner_pages' => ['home'],
            'published_at' => now()->subMinute(),
            'expires_at' => null,
            'created_by' => null,
        ]);

        $response = $this->actingAs($agentOfA)->getJson('/api/v1/announcements')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($ownBanner->id, $ids);
        $this->assertNotContains($foreignBanner->id, $ids);
    }
}
