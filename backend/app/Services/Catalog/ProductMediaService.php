<?php

namespace App\Services\Catalog;

use App\Enums\MediaProcessingStatus;
use App\Enums\MediaSourceType;
use App\Enums\ProductMediaPurpose;
use App\Enums\ProductMediaType;
use App\Jobs\CompressUploadedVideo;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use App\Support\Media\StoredFileName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// ADR-007 — Product's image/video gallery. Files live on the 'local'
// (private) disk, same reasoning as ProductSalesMaterialService: even
// though product media is meant to be shown to prospects, it's served
// through our own access-checked stream (ProductMediaController::show()),
// never a direct public URL — consistent with the rest of this codebase,
// and distinct from the DELIBERATE, narrow public-link exception built
// for sales materials (ADR-007 Decision 3), which this class has no part in.
class ProductMediaService
{
    private const DISK = 'local';

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(Product $product, array $data, ?UploadedFile $file, User $actor): ProductMedia
    {
        $isVideo = $data['media_type'] === ProductMediaType::Video->value;
        $isEmbed = $isVideo && ($data['source_type'] ?? null) === MediaSourceType::Embed->value;

        $attributes = [
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'uploaded_by_user_id' => $actor->id,
            'media_type' => $data['media_type'],
            // Images are always 'upload' (StoreProductMediaRequest
            // prohibits source_type for them) — set here rather than
            // trusting an absent request field.
            'source_type' => $isVideo ? $data['source_type'] : MediaSourceType::Upload->value,
            // TASK-097 — 'detail' is the default so pre-existing callers
            // (and the DB default) agree.
            'purpose' => $data['purpose'] ?? ProductMediaPurpose::Detail->value,
            'is_primary' => $data['is_primary'] ?? false,
            'sort_order' => $data['sort_order'] ?? 0,
        ];

        if ($isEmbed) {
            $attributes['embed_url'] = $data['embed_url'];
        } else {
            $attributes['file_path'] = $file->storeAs(
                "product-media/{$product->company_id}/{$product->id}",
                StoredFileName::random($file),
                self::DISK,
            );

            if ($isVideo) {
                $attributes['processing_status'] = MediaProcessingStatus::Pending->value;
            }
        }

        return DB::transaction(function () use ($product, $attributes) {
            $isCover = $attributes['purpose'] === ProductMediaPurpose::Cover->value;

            // TASK-097 — the FIRST cover a product ever gets becomes the
            // primary automatically. Without this the admin has to
            // upload and then separately click a star, and until they do
            // the storefront card silently falls back to whatever
            // ProductResource's `?? first` clause picks — which is how
            // products ended up showing detail screenshots as their card
            // image in the first place.
            if ($isCover && ! $attributes['is_primary'] && ! $this->hasCover($product)) {
                $attributes['is_primary'] = true;
            }

            if ($attributes['is_primary']) {
                $this->clearExistingPrimary($product);
            }

            $media = ProductMedia::create($attributes);

            if ($media->media_type === ProductMediaType::Video && $media->source_type === MediaSourceType::Upload) {
                CompressUploadedVideo::dispatch($media->id, ProductMedia::class, self::DISK);
            }

            return $media;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProductMedia $media, array $data): ProductMedia
    {
        return DB::transaction(function () use ($media, $data) {
            if (($data['is_primary'] ?? false) === true) {
                $this->clearExistingPrimary($media->product);
            }

            $media->update($data);

            return $media;
        });
    }

    public function delete(ProductMedia $media): void
    {
        if ($media->file_path) {
            Storage::disk(self::DISK)->delete($media->file_path);
        }
        if ($media->thumbnail_path) {
            Storage::disk(self::DISK)->delete($media->thumbnail_path);
        }

        $wasPrimaryCover = $media->is_primary && $media->purpose === ProductMediaPurpose::Cover;
        $product = $media->product;

        $media->delete();

        if ($wasPrimaryCover && $product) {
            $this->promoteNextCover($product);
        }
    }

    public function disk(): string
    {
        return self::DISK;
    }

    private function hasCover(Product $product): bool
    {
        return ProductMedia::where('product_id', $product->id)
            ->where('purpose', ProductMediaPurpose::Cover->value)
            ->exists();
    }

    private function clearExistingPrimary(Product $product): void
    {
        ProductMedia::where('product_id', $product->id)->where('is_primary', true)->update(['is_primary' => false]);
    }

    /**
     * TASK-097 — when a cover is deleted, hand `is_primary` to the next
     * cover in line rather than leaving the product with none.
     *
     * A product with covers but no primary is the worst of both states:
     * ProductResource::thumbnail_url falls through to its `?? first`
     * branch and the card shows a photo the admin never chose, with no
     * star anywhere in the UI to explain it.
     */
    private function promoteNextCover(Product $product): void
    {
        $next = ProductMedia::where('product_id', $product->id)
            ->where('purpose', ProductMediaPurpose::Cover->value)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        $next?->update(['is_primary' => true]);
    }
}
