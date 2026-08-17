<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-007 — Academy module video: upload OR iframe embed. Deliberately
// reuses the EXISTING `content_ref` column for both cases (it was
// already documented as "opaque string, caller determines structure" —
// Module's own comment) instead of adding a parallel embed_url column
// like product_sales_materials got: content_ref holds the embed URL
// when source_type=embed, or the private-disk file_path when
// source_type=upload. source_type/processing_status are both nullable
// and only meaningful when content_type=video — every existing
// pdf/link/quiz row is unaffected (source_type stays null for them, not
// defaulted to 'upload', since neither value would be meaningful).
// Plain ADD COLUMN both drivers — no ->change() on any existing column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->string('source_type')->nullable()->after('content_type'); // App\Enums\MediaSourceType: upload|embed — video content_type only
            $table->string('processing_status')->nullable()->after('content_ref'); // App\Enums\MediaProcessingStatus — uploaded video only
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'processing_status']);
        });
    }
};
