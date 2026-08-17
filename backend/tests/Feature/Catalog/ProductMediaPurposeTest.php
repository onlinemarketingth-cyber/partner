<?php

namespace Tests\Feature\Catalog;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-097 / ADR-022 — the cover ("รูปสินค้า") / detail
 * ("รายละเอียดสินค้า") split.
 *
 * The cases worth writing here are the ones where getting it wrong is
 * INVISIBLE rather than loud: a cover silently landing in the detail
 * gallery, a product left with covers but no primary after a delete, or
 * a detail screenshot quietly winning the storefront card. Each of those
 * renders a perfectly normal-looking page showing the wrong image.
 */
class ProductMediaPurposeTest extends TestCase
{
    use RefreshDatabase;

    private function adminAndProduct(): array
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        return [$admin, $product];
    }

    public function test_media_defaults_to_the_detail_gallery(): void
    {
        Storage::fake('local');
        [$admin, $product] = $this->adminAndProduct();

        // No `purpose` in the payload — every caller that predates
        // TASK-097 looks exactly like this, and must keep behaving the
        // way it always did.
        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/media", [
                'media_type' => 'image',
                'file' => UploadedFile::fake()->image('shot.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.purpose', 'detail');
    }

    public function test_the_first_cover_becomes_primary_without_being_asked(): void
    {
        Storage::fake('local');
        [$admin, $product] = $this->adminAndProduct();

        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/media", [
                'media_type' => 'image',
                'purpose' => 'cover',
                'file' => UploadedFile::fake()->image('front.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.purpose', 'cover')
            ->assertJsonPath('data.is_primary', true);

        // The second one must NOT steal it — Shopee-style ordering means
        // later uploads queue behind the chosen photo, they don't replace it.
        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/media", [
                'media_type' => 'image',
                'purpose' => 'cover',
                'file' => UploadedFile::fake()->image('back.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_primary', false);

        $this->assertSame(1, ProductMedia::where('product_id', $product->id)->where('is_primary', true)->count());
    }

    public function test_a_detail_image_does_not_auto_become_primary(): void
    {
        Storage::fake('local');
        [$admin, $product] = $this->adminAndProduct();

        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/media", [
                'media_type' => 'image',
                'purpose' => 'detail',
                'file' => UploadedFile::fake()->image('spec-sheet.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_primary', false);
    }

    public function test_a_cover_cannot_be_a_video_or_an_embed(): void
    {
        Storage::fake('local');
        [$admin, $product] = $this->adminAndProduct();

        // An embedded YouTube link as the "product photo" would render
        // the storefront card blank — reject it at the API, not just in
        // the UI.
        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/media", [
                'media_type' => 'video',
                'source_type' => 'embed',
                'purpose' => 'cover',
                'embed_url' => 'https://youtu.be/abc123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('purpose');
    }

    public function test_deleting_the_primary_cover_promotes_the_next_one(): void
    {
        Storage::fake('local');
        [$admin, $product] = $this->adminAndProduct();

        $first = $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/media", [
            'media_type' => 'image', 'purpose' => 'cover', 'file' => UploadedFile::fake()->image('a.jpg'),
        ])->json('data.id');

        $second = $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/media", [
            'media_type' => 'image', 'purpose' => 'cover', 'file' => UploadedFile::fake()->image('b.jpg'),
        ])->json('data.id');

        $this->actingAs($admin)->deleteJson("/api/v1/product-media/{$first}")->assertNoContent();

        // Leaving zero primaries is the failure mode this guards: the
        // card would fall through to "first media" and show whatever
        // happened to be uploaded earliest.
        $this->assertDatabaseHas('product_media', ['id' => $second, 'is_primary' => true]);
    }

    public function test_the_index_can_be_filtered_by_purpose(): void
    {
        Storage::fake('local');
        [$admin, $product] = $this->adminAndProduct();

        $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/media", [
            'media_type' => 'image', 'purpose' => 'cover', 'file' => UploadedFile::fake()->image('cover.jpg'),
        ])->assertCreated();

        $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/media", [
            'media_type' => 'image', 'purpose' => 'detail', 'file' => UploadedFile::fake()->image('detail.jpg'),
        ])->assertCreated();

        $this->actingAs($admin)
            ->getJson("/api/v1/products/{$product->id}/media?purpose=cover")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.purpose', 'cover');

        $this->actingAs($admin)
            ->getJson("/api/v1/products/{$product->id}/media")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // A typo must be a 422, not an empty gallery that reads as
        // "someone deleted all the photos".
        $this->actingAs($admin)
            ->getJson("/api/v1/products/{$product->id}/media?purpose=banner")
            ->assertStatus(422);
    }

    public function test_the_storefront_card_prefers_a_cover_over_an_older_detail_image(): void
    {
        Storage::fake('local');
        [$admin, $product] = $this->adminAndProduct();

        // Uploaded FIRST and flagged primary — under the pre-TASK-097
        // rule this would have won the card.
        $detail = $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/media", [
            'media_type' => 'image', 'purpose' => 'detail', 'file' => UploadedFile::fake()->image('old.jpg'),
        ])->json('data.id');

        $this->actingAs($admin)->putJson("/api/v1/product-media/{$detail}", ['is_primary' => true])->assertOk();

        $cover = $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/media", [
            'media_type' => 'image', 'purpose' => 'cover', 'file' => UploadedFile::fake()->image('new.jpg'),
        ])->json('data.id');

        $thumbnail = $this->actingAs($admin)
            ->getJson('/api/v1/products')
            ->assertOk()
            ->json('data.0.thumbnail_url');

        $this->assertStringContainsString((string) $cover, (string) $thumbnail);
        $this->assertStringNotContainsString("/{$detail}/", (string) $thumbnail);
    }
}
