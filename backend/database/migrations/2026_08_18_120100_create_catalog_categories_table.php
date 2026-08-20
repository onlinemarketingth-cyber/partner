<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-036 §2 — the shared ("global") category standard, same reasoning
// and no-TenantScope/Super-Admin-only-write contract as catalog_brands
// above. Mirrors product_categories' shape (name, icon, sort_order,
// is_active, soft-deletes) minus company_id, again so
// CatalogCategoryResource can match ProductCategoryResource's shape
// 1:1 for a linked product.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // TASK-068 / ADR-020 row 3 — same curated-icon-whitelist
            // convention as product_categories.icon.
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_categories');
    }
};
