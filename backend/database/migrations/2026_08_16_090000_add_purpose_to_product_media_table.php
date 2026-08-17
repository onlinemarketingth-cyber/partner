<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-097 / ADR-022 — split product_media into two galleries.
 *
 * WHY a column rather than a second table: everything else about a
 * product photo and a detail-gallery item is identical (tenant scoping,
 * private-disk storage, the stream/thumbnail/download routes, the
 * Policy). A `product_cover_images` table would have duplicated
 * ProductMediaService, ProductMediaController and their tests in full to
 * express one boolean's worth of difference.
 *
 * BACKFILL: rows already flagged is_primary become 'cover'. That single
 * row per product is precisely the one ProductResource::thumbnail_url
 * was already resolving, so every existing storefront card keeps the
 * exact image it has today and the new "รูปสินค้า" section is not empty
 * on first load. Everything else stays 'detail' — the default — so no
 * existing detail gallery loses an item except that one promoted photo,
 * which is the intended semantic (it IS the product photo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            // App\Enums\ProductMediaPurpose. Defaulting to 'detail' (not
            // 'cover') is deliberate: an un-migrated writer, or any code
            // path that forgets to pass purpose, must land in the
            // gallery that has no storefront consequence.
            $table->string('purpose')->default('detail')->after('source_type');

            $table->index(['company_id', 'product_id', 'purpose', 'sort_order'], 'product_media_purpose_idx');
        });

        DB::table('product_media')->where('is_primary', true)->update(['purpose' => 'cover']);
    }

    public function down(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            $table->dropIndex('product_media_purpose_idx');
            $table->dropColumn('purpose');
        });
    }
};
