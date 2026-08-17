<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-068 / ADR-020 row 3 — category icons reuse the existing curated
// Icon.vue whitelist (same shape as company_theme_settings.nav_icon_overrides
// / ADR-018), NOT a per-category image upload. `icon` stores an Icon.vue
// icon name string; nullable = no icon picked yet (frontend falls back to
// a neutral default glyph). Unlike nav_icon_overrides, this value IS
// validated server-side against a whitelist (see
// App\Support\CuratedIcons and UpdateProductCategoryRequest) — TASK-068
// explicitly calls for reject-unknown-names-server-side-too here, since a
// category icon is public-facing storefront content, not an internal
// admin-only nav setting.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->string('icon')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
