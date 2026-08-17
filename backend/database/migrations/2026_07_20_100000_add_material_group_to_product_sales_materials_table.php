<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Human-requested 2026-07-20: agents wanted sales materials organizable
// into named sections ("บทเรียนที่ 1", "บทเรียนที่ 2", ...) so a client
// pitch can bundle "which PDFs/videos belong to which topic". Confirmed
// with the human this is purely a free-text label on this table — NOT a
// link into the Academy module/certification system (Section 2 glossary,
// BR-1), which is a separate domain entirely. Same free-text-grouping
// shape as product_specs.spec_group (see that migration/GroupCombobox.vue)
// — nullable, ungrouped materials stay valid.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_sales_materials', function (Blueprint $table) {
            $table->string('material_group')->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_sales_materials', function (Blueprint $table) {
            $table->dropColumn('material_group');
        });
    }
};
