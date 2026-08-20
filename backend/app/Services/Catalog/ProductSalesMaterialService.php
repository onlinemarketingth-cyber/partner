<?php

namespace App\Services\Catalog;

use App\Enums\MediaProcessingStatus;
use App\Enums\MediaSourceType;
use App\Jobs\CompressUploadedVideo;
use App\Models\Product;
use App\Models\ProductSalesMaterial;
use App\Models\User;
use App\Support\Media\StoredFileName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// Human-requested: Product Catalog sales/marketing collateral. Files
// live on the 'local' disk (storage/app/private, NOT the 'public' disk
// UserProfileService uses) — same reasoning as ClientDocumentService:
// materials can be company-confidential, so every read goes through
// ProductSalesMaterialController::download's access-checked stream,
// never a direct URL (the ONE deliberate exception being ADR-007's
// signed share-link route, handled by SalesMaterialShareLinkService,
// not here).
//
// ADR-007 — gained video (upload or embed). An embedded material has no
// file at all (embed_url only); an uploaded video is compressed async
// (CompressUploadedVideo) exactly like ProductMediaService's video path.
class ProductSalesMaterialService
{
    private const DISK = 'local';

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(Product $product, array $data, ?UploadedFile $file, User $actor): ProductSalesMaterial
    {
        $sourceType = $data['source_type'] ?? MediaSourceType::Upload->value;
        $isEmbed = $sourceType === MediaSourceType::Embed->value;

        $attributes = [
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'uploaded_by_user_id' => $actor->id,
            'material_group' => $data['material_group'] ?? null,
            'source_type' => $sourceType,
        ];

        if ($isEmbed) {
            $attributes['embed_url'] = $data['embed_url'];
        } else {
            $path = $file->storeAs(
                "product-materials/{$product->company_id}/{$product->id}",
                StoredFileName::random($file),
                self::DISK,
            );

            $attributes['file_path'] = $path;
            $attributes['original_filename'] = $file->getClientOriginalName();
            $attributes['mime_type'] = $file->getClientMimeType();
            $attributes['size_bytes'] = $file->getSize();

            if (str_starts_with($attributes['mime_type'], 'video/')) {
                $attributes['processing_status'] = MediaProcessingStatus::Pending->value;
            }
        }

        $material = ProductSalesMaterial::create($attributes);

        if ($material->source_type === MediaSourceType::Upload && $material->processing_status === MediaProcessingStatus::Pending) {
            CompressUploadedVideo::dispatch($material->id, ProductSalesMaterial::class, self::DISK);
        }

        return $material;
    }

    /**
     * Human-requested 2026-07-20: let admins move a material into a
     * different group after upload without deleting/re-uploading the
     * file. Deliberately narrow — only material_group is mutable here,
     * everything else about an uploaded/embedded material is immutable
     * post-creation (same posture as the rest of this Service).
     */
    public function updateGroup(ProductSalesMaterial $material, ?string $materialGroup): ProductSalesMaterial
    {
        $material->update(['material_group' => $materialGroup]);

        return $material;
    }

    public function delete(ProductSalesMaterial $material): void
    {
        if ($material->file_path) {
            Storage::disk(self::DISK)->delete($material->file_path);
        }
        $material->delete();
    }

    public function disk(): string
    {
        return self::DISK;
    }
}
