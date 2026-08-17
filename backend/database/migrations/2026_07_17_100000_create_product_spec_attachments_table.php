<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-008 — Product's spec image/PDF gallery, separate from the
// hero/thumbnail gallery (product_media, ADR-007). Mirrors
// product_media's shape exactly (media_type, source_type, file_path/
// embed_url, thumbnail_path, sort_order, processing_status) plus an
// added page_count (PDF only). Kept as its own table/enum rather than
// overloading product_media's image|video enum — see ADR-008 Decision 2.
// source_type decides which of file_path/embed_url is populated — never
// both, enforced in ProductSpecAttachmentService, not the DB (same
// "pair enforced in application code" pattern as product_media).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_spec_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('media_type'); // App\Enums\ProductSpecAttachmentType: image|pdf
            $table->string('source_type'); // App\Enums\MediaSourceType: upload|embed
            $table->string('file_path')->nullable(); // set when source_type=upload — private disk, same as ProductMedia
            $table->string('embed_url')->nullable(); // set when source_type=embed
            $table->string('thumbnail_path')->nullable(); // only ever set for an uploaded PDF (ADR-008's GeneratePdfThumbnail job)
            $table->unsignedInteger('page_count')->nullable(); // PDF only — set by GeneratePdfThumbnail
            $table->unsignedInteger('sort_order')->default(0);
            // App\Enums\MediaProcessingStatus — null for images and for
            // embedded attachments (nothing to process); pending/
            // processing/ready/failed only for an uploaded PDF awaiting/
            // undergoing thumbnail generation (ADR-008's GeneratePdfThumbnail job).
            $table->string('processing_status')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'product_id', 'sort_order'], 'product_spec_attachments_product_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_spec_attachments');
    }
};
