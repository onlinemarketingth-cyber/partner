<?php

namespace App\Services\Catalog;

use App\Enums\MediaProcessingStatus;
use App\Enums\MediaSourceType;
use App\Enums\ProductSpecAttachmentType;
use App\Jobs\GeneratePdfThumbnail;
use App\Models\Product;
use App\Models\ProductSpecAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// ADR-008 — Product's spec image/PDF gallery. Files live on the 'local'
// (private) disk, same reasoning as ProductMediaService: served through
// our own access-checked stream (ProductSpecAttachmentController::stream()),
// never a direct public URL (Section 5 rule 6).
class ProductSpecAttachmentService
{
    private const DISK = 'local';

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(Product $product, array $data, ?UploadedFile $file, User $actor): ProductSpecAttachment
    {
        $isPdf = $data['media_type'] === ProductSpecAttachmentType::Pdf->value;
        $isEmbed = ($data['source_type'] ?? null) === MediaSourceType::Embed->value;

        $attributes = [
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'uploaded_by_user_id' => $actor->id,
            'media_type' => $data['media_type'],
            'source_type' => $data['source_type'],
            'sort_order' => $data['sort_order'] ?? 0,
        ];

        if ($isEmbed) {
            $attributes['embed_url'] = $data['embed_url'];
        } else {
            $attributes['file_path'] = $file->storeAs(
                "product-spec-attachments/{$product->company_id}/{$product->id}",
                Str::uuid()->toString().'.'.$file->getClientOriginalExtension(),
                self::DISK,
            );

            if ($isPdf) {
                $attributes['processing_status'] = MediaProcessingStatus::Pending->value;
            }
        }

        return DB::transaction(function () use ($attributes) {
            $attachment = ProductSpecAttachment::create($attributes);

            if ($attachment->media_type === ProductSpecAttachmentType::Pdf && $attachment->source_type === MediaSourceType::Upload) {
                GeneratePdfThumbnail::dispatch($attachment->id, ProductSpecAttachment::class, self::DISK);
            }

            return $attachment;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProductSpecAttachment $attachment, array $data): ProductSpecAttachment
    {
        // No is_primary concept here (unlike ProductMedia) — only
        // sort_order is patchable after creation.
        $attachment->update($data);

        return $attachment;
    }

    public function delete(ProductSpecAttachment $attachment): void
    {
        if ($attachment->file_path) {
            Storage::disk(self::DISK)->delete($attachment->file_path);
        }
        if ($attachment->thumbnail_path) {
            Storage::disk(self::DISK)->delete($attachment->thumbnail_path);
        }

        $attachment->delete();
    }

    public function disk(): string
    {
        return self::DISK;
    }
}
