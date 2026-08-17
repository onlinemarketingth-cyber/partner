<?php

namespace Tests\Feature\Engagement;

use App\Models\Announcement;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Human request (2026-07-23): "สามารถเพิ่มรูป และวิดีโอในประกาศได้" — image
// (always upload) + video (upload OR embed link, App\Enums\MediaSourceType
// — same mutual-exclusion shape ADR-007 already established for
// ProductMedia/ProductSalesMaterial). Covers: image upload/removal,
// video upload vs. embed mutual exclusion, replacing a file deletes the
// old one from disk, and destroy() cleans up both files. Follows
// UserProfileTest's pattern of asserting against the model's own
// stored path column rather than parsing the returned URL.
class AnnouncementMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_create_an_announcement_with_an_image(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $image = UploadedFile::fake()->image('promo.jpg');

        $response = $this->actingAs($admin)->post('/api/v1/announcements', [
            'title' => 'มีรูป',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
            'image' => $image,
        ])->assertCreated();

        $announcement = Announcement::find($response->json('data.id'));
        $this->assertNotNull($announcement->image_path);
        $this->assertNotNull($response->json('data.image_url'));
        Storage::disk('public')->assertExists($announcement->image_path);
    }

    public function test_creating_an_announcement_with_a_video_upload_stores_the_file(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $video = UploadedFile::fake()->create('demo.mp4', 5000, 'video/mp4');

        $response = $this->actingAs($admin)->post('/api/v1/announcements', [
            'title' => 'มีวิดีโอ',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
            'video_source_type' => 'upload',
            'video' => $video,
        ])->assertCreated();

        $announcement = Announcement::find($response->json('data.id'));
        $this->assertSame('upload', $announcement->video_source_type->value);
        $this->assertNotNull($announcement->video_path);
        $this->assertSame('upload', $response->json('data.video.type'));
        Storage::disk('public')->assertExists($announcement->video_path);
    }

    public function test_creating_an_announcement_with_a_video_embed_link_never_touches_storage(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->postJson('/api/v1/announcements', [
            'title' => 'ลิงก์ YouTube',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
            'video_source_type' => 'embed',
            'video_embed_url' => 'https://youtube.com/watch?v=abc123',
        ])->assertCreated();

        $announcement = Announcement::find($response->json('data.id'));
        $this->assertNull($announcement->video_path);
        $this->assertSame('https://youtube.com/watch?v=abc123', $announcement->video_embed_url);
        $this->assertSame('embed', $response->json('data.video.type'));
        $this->assertSame('https://youtube.com/watch?v=abc123', $response->json('data.video.url'));
    }

    public function test_sending_both_a_video_file_and_source_type_embed_is_rejected(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $video = UploadedFile::fake()->create('demo.mp4', 5000, 'video/mp4');

        $this->actingAs($admin)->post('/api/v1/announcements', [
            'title' => 'ผิดเงื่อนไข',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
            'video_source_type' => 'embed',
            'video' => $video,
        ])->assertStatus(422);
    }

    public function test_source_type_upload_without_a_video_file_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/announcements', [
            'title' => 'ไม่มีไฟล์',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
            'video_source_type' => 'upload',
        ])->assertStatus(422);
    }

    public function test_replacing_an_image_deletes_the_old_file_from_disk(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $create = $this->actingAs($admin)->post('/api/v1/announcements', [
            'title' => 'เดิม',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
            'image' => UploadedFile::fake()->image('old.jpg'),
        ])->assertCreated();
        $announcement = Announcement::find($create->json('data.id'));
        $oldPath = $announcement->image_path;
        Storage::disk('public')->assertExists($oldPath);

        $this->actingAs($admin)
            ->post("/api/v1/announcements/{$announcement->id}", [
                '_method' => 'PUT',
                'image' => UploadedFile::fake()->image('new.jpg'),
            ])
            ->assertOk();

        $newPath = $announcement->refresh()->image_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_remove_image_flag_clears_the_image_and_deletes_the_file(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $create = $this->actingAs($admin)->post('/api/v1/announcements', [
            'title' => 'มีรูป',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
            'image' => UploadedFile::fake()->image('old.jpg'),
        ])->assertCreated();
        $announcement = Announcement::find($create->json('data.id'));
        $oldPath = $announcement->image_path;

        $response = $this->actingAs($admin)->putJson("/api/v1/announcements/{$announcement->id}", [
            'remove_image' => true,
        ])->assertOk();

        $this->assertNull($response->json('data.image_url'));
        $this->assertNull($announcement->refresh()->image_path);
        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_deleting_an_announcement_removes_its_image_and_video_files(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $create = $this->actingAs($admin)->post('/api/v1/announcements', [
            'title' => 'ลบทั้งหมด',
            'content' => 'เนื้อหา',
            'audience' => 'all_agents',
            'image' => UploadedFile::fake()->image('old.jpg'),
            'video_source_type' => 'upload',
            'video' => UploadedFile::fake()->create('demo.mp4', 5000, 'video/mp4'),
        ])->assertCreated();
        $announcement = Announcement::find($create->json('data.id'));
        $imagePath = $announcement->image_path;
        $videoPath = $announcement->video_path;

        $this->actingAs($admin)->delete("/api/v1/announcements/{$announcement->id}")->assertNoContent();

        Storage::disk('public')->assertMissing($imagePath);
        Storage::disk('public')->assertMissing($videoPath);
    }
}
