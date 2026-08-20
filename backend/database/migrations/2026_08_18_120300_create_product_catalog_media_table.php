<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-036 §2/§8 — shared media gallery for a product_catalog_item.
// Deliberately a NEW, dedicated table rather than widening the existing
// product_media table's company_id/product_id to nullable: product_media
// is depended on by TASK-097's cover/detail purpose logic, the video
// compression job, thumbnail resolution, and ProductResource's
// thumbnail_url — all of which assume company_id/product_id are always
// present. Keeping catalog-owned media in its own table means every one
// of those existing behaviours needs ZERO changes for a standalone
// (catalog_item_id = null) product, matching ADR-036 §1's "zero blast
// radius on existing data" principle exactly. ProductMediaService and
// this table's own (smaller) service stay two separate call paths that
// happen to share column shapes and the same App\Enums\ProductMediaType /
// MediaSourceType / MediaProcessingStatus enums.
//
// No company_id (global, like product_catalog_items itself) — access
// control is "can you see this catalog item at all", enforced in the
// Service layer (TASK-212), not by a tenant filter on this table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_catalog_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained('product_catalog_items')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('media_type'); // App\Enums\ProductMediaType: image|video
            $table->string('source_type'); // App\Enums\MediaSourceType: upload|embed
            $table->string('file_path')->nullable();
            $table->string('embed_url')->nullable();
            $table->string('thumbnail_path')->nullable();
            // TASK-097 — cover vs detail, same enum/contract as product_media.purpose.
            $table->string('purpose')->default('detail');
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('processing_status')->nullable();
            $table->timestamps();

            $table->index(['catalog_item_id', 'sort_order'], 'product_catalog_media_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_catalog_media');
    }
};
