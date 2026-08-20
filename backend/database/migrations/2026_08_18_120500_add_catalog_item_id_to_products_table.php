<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-036 §2/§3 — the opt-in link from a company's own `products` row to
// the shared product_catalog_items identity. Nullable, defaultless: every
// existing product stays untouched (catalog_item_id = null = "standalone",
// today's behavior exactly — ADR-036 §1's "zero blast radius" principle).
//
// restrictOnDelete (not cascade, not nullOnDelete): once linked, a catalog
// item's name/brand/category feed every company's product display (see the
// next migration, which makes products.name/brand_id/category_id nullable
// specifically so a linked row no longer needs its own copy). Silently
// nulling catalog_item_id on delete would silently blank those fields out
// from under every company that linked it. Deleting a shared catalog item
// must be an explicit, deliberate action (Super Admin unlinks each product
// first) — same restrictOnDelete convention product_catalog_items itself
// already uses for catalog_brand_id/catalog_category_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('catalog_item_id')->nullable()->after('category_id')
                ->constrained('product_catalog_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_item_id');
        });
    }
};
