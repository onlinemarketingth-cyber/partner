<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-007 — Product's image/video gallery (Amazon-style product detail).
// source_type decides which of file_path/embed_url is populated — never
// both, enforced in ProductMediaService, not the DB (same "pair
// enforced in application code" pattern already used for TASK-024's
// renewal fields and TASK-026's co_agent_id/split_percentage).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('media_type'); // App\Enums\ProductMediaType: image|video
            $table->string('source_type'); // App\Enums\MediaSourceType: upload|embed
            $table->string('file_path')->nullable(); // set when source_type=upload — private disk, same as ProductSalesMaterial
            $table->string('embed_url')->nullable(); // set when source_type=embed
            $table->string('thumbnail_path')->nullable(); // only ever set for an uploaded (not embedded) video
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            // App\Enums\MediaProcessingStatus — null for images and for
            // embedded video (nothing to process); pending/processing/
            // ready/failed only for an uploaded video awaiting/undergoing
            // compression (ADR-007's CompressUploadedVideo job).
            $table->string('processing_status')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'product_id', 'sort_order'], 'product_media_product_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
    }
};
