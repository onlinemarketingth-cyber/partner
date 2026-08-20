<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-036 §2 — the shared ("global") product identity a company's own
// `products` row can opt into via the new `products.catalog_item_id`
// (see 2026_08_18_120500_add_catalog_item_id_to_products_table). No
// company_id, no TenantScope — Super-Admin-only write (ProductCatalogItemPolicy,
// TASK-212). Holds exactly what ADR-036's decision table says must be
// IDENTICAL across every company that sells this product: name,
// description, spec_description, brand, category. price_satang,
// commission config, is_active, etc. all stay on the per-company
// `products` row (ADR-036 §3) — never duplicated here.
//
// restrictOnDelete on both brand/category FKs, matching products' own
// brand_id/category_id convention (2026_07_09_100040_create_products_table)
// — never silently orphan a catalog item's classification.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_brand_id')->constrained('catalog_brands')->restrictOnDelete();
            $table->foreignId('catalog_category_id')->constrained('catalog_categories')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('spec_description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_catalog_items');
    }
};
