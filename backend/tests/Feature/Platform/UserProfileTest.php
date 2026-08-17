<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Personal profile customization (avatar + background) — human-requested
// feature, not tied to any BR. Every endpoint is self-scoped (/me/...,
// no {user} route parameter exists), so there's no cross-user IDOR case
// to test the way ClientDocumentTest does for a colleague's client —
// instead these tests confirm the file always lands under the ACTING
// user's own company_id/id path and that switching background type
// cleans up the previous file.
class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_upload_their_own_avatar(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $file = UploadedFile::fake()->image('photo.jpg');

        $this->actingAs($agent)
            ->postJson('/api/v1/me/avatar', ['avatar' => $file])
            ->assertOk()
            ->assertJsonPath('data.avatar_url', fn ($url) => is_string($url) && str_contains($url, "avatars/{$company->id}/"));

        $agent->refresh();
        Storage::disk('public')->assertExists($agent->avatar_path);
    }

    public function test_avatar_upload_rejects_a_non_image_file(): void
    {
        Storage::fake('public');
        $agent = User::factory()->agent()->create();

        $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

        $this->actingAs($agent)
            ->postJson('/api/v1/me/avatar', ['avatar' => $file])
            ->assertUnprocessable();
    }

    public function test_uploading_a_new_avatar_deletes_the_previous_file(): void
    {
        Storage::fake('public');
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('first.jpg')])->assertOk();
        $firstPath = $agent->refresh()->avatar_path;

        $this->actingAs($agent)->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('second.jpg')])->assertOk();
        $secondPath = $agent->refresh()->avatar_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_agent_can_delete_their_avatar(): void
    {
        Storage::fake('public');
        $agent = User::factory()->agent()->create();
        $this->actingAs($agent)->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('photo.jpg')])->assertOk();
        $path = $agent->refresh()->avatar_path;

        $this->actingAs($agent)
            ->deleteJson('/api/v1/me/avatar')
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);

        Storage::disk('public')->assertMissing($path);
        $this->assertNull($agent->refresh()->avatar_path);
    }

    public function test_agent_can_set_a_gradient_background(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)
            ->putJson('/api/v1/me/background', ['color1' => '#FF0000', 'color2' => '#00FF00', 'angle' => 45])
            ->assertOk()
            ->assertJsonPath('data.background.type', 'gradient')
            ->assertJsonPath('data.background.config.color1', '#FF0000')
            ->assertJsonPath('data.background.config.angle', 45);
    }

    public function test_gradient_background_rejects_an_invalid_color(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)
            ->putJson('/api/v1/me/background', ['color1' => 'not-a-color', 'color2' => '#00FF00'])
            ->assertUnprocessable();
    }

    public function test_agent_can_upload_a_background_image(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/me/background/image', ['background_image' => UploadedFile::fake()->image('bg.jpg')])
            ->assertOk()
            ->assertJsonPath('data.background.type', 'image')
            ->assertJsonPath('data.background.image_url', fn ($url) => is_string($url) && str_contains($url, "backgrounds/{$company->id}/"));
    }

    public function test_switching_from_image_to_gradient_deletes_the_old_image_file(): void
    {
        Storage::fake('public');
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)->postJson('/api/v1/me/background/image', ['background_image' => UploadedFile::fake()->image('bg.jpg')])->assertOk();
        $imagePath = $agent->refresh()->background_image_path;
        Storage::disk('public')->assertExists($imagePath);

        $this->actingAs($agent)
            ->putJson('/api/v1/me/background', ['color1' => '#111111', 'color2' => '#222222'])
            ->assertOk()
            ->assertJsonPath('data.background.type', 'gradient')
            ->assertJsonPath('data.background.image_url', null);

        Storage::disk('public')->assertMissing($imagePath);
        $this->assertNull($agent->refresh()->background_image_path);
    }

    public function test_switching_from_gradient_to_image_clears_the_gradient_config(): void
    {
        Storage::fake('public');
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)->putJson('/api/v1/me/background', ['color1' => '#111111', 'color2' => '#222222'])->assertOk();

        $this->actingAs($agent)
            ->postJson('/api/v1/me/background/image', ['background_image' => UploadedFile::fake()->image('bg.jpg')])
            ->assertOk()
            ->assertJsonPath('data.background.type', 'image')
            ->assertJsonPath('data.background.config', null);
    }

    public function test_agent_can_reset_their_background_to_default(): void
    {
        Storage::fake('public');
        $agent = User::factory()->agent()->create();
        $this->actingAs($agent)->putJson('/api/v1/me/background', ['color1' => '#111111', 'color2' => '#222222'])->assertOk();

        $this->actingAs($agent)
            ->deleteJson('/api/v1/me/background')
            ->assertOk()
            ->assertJsonPath('data.background.type', null);
    }

    public function test_profile_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('photo.jpg')])->assertUnauthorized();
        $this->putJson('/api/v1/me/background', ['color1' => '#111111', 'color2' => '#222222'])->assertUnauthorized();
    }

    // --- Self-service name change ---

    public function test_agent_can_update_their_own_name(): void
    {
        $agent = User::factory()->agent()->create(['first_name' => 'Old', 'last_name' => 'Name']);

        $this->actingAs($agent)
            ->putJson('/api/v1/me/name', ['first_name' => 'Somchai', 'last_name' => 'Jaidee'])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Somchai')
            ->assertJsonPath('data.last_name', 'Jaidee')
            ->assertJsonPath('data.name', 'Somchai Jaidee');

        $this->assertSame('Somchai Jaidee', $agent->fresh()->name);
    }

    public function test_updating_name_requires_both_fields(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)
            ->putJson('/api/v1/me/name', ['first_name' => 'Somchai'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('last_name');
    }

    // --- Self-service password change ---

    public function test_agent_can_change_their_own_password(): void
    {
        $agent = User::factory()->agent()->create(['password' => 'OldPassword123']);
        $oldHash = $agent->password;

        $this->actingAs($agent)
            ->putJson('/api/v1/me/password', [
                'current_password' => 'OldPassword123',
                'password' => 'NewPassword456',
                'password_confirmation' => 'NewPassword456',
            ])
            ->assertOk();

        $this->assertNotSame($oldHash, $agent->fresh()->password);
    }

    public function test_password_change_rejects_a_wrong_current_password(): void
    {
        $agent = User::factory()->agent()->create(['password' => 'OldPassword123']);

        $this->actingAs($agent)
            ->putJson('/api/v1/me/password', [
                'current_password' => 'WrongPassword',
                'password' => 'NewPassword456',
                'password_confirmation' => 'NewPassword456',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
    }

    public function test_password_change_rejects_mismatched_confirmation(): void
    {
        $agent = User::factory()->agent()->create(['password' => 'OldPassword123']);

        $this->actingAs($agent)
            ->putJson('/api/v1/me/password', [
                'current_password' => 'OldPassword123',
                'password' => 'NewPassword456',
                'password_confirmation' => 'Different789',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_name_and_password_endpoints_require_authentication(): void
    {
        $this->putJson('/api/v1/me/name', ['first_name' => 'a', 'last_name' => 'b'])->assertUnauthorized();
        $this->putJson('/api/v1/me/password', ['current_password' => 'x', 'password' => 'y', 'password_confirmation' => 'y'])->assertUnauthorized();
    }
}
