<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-036 §2 — the shared ("global") brand standard for cross-company
// catalog items. Deliberately NOT company-scoped (no company_id column,
// no TenantScope on the Model) — the whole point is that every company
// sees the same brand name for a linked product (human decision,
// ADR-036's decision table: "มาตรฐานเดียวกัน"). Write access is
// Super-Admin-only (CatalogBrandPolicy, TASK-212) — Company Admin never
// creates/edits a row here, only reads it through a linked product.
//
// Mirrors brands' own shape (name, logo_path, is_active, soft-deletes)
// minus company_id — same column names on purpose so CatalogBrandResource
// can output an identical shape to BrandResource and the frontend's
// existing `Brand { id, name, is_active }` TypeScript interface keeps
// working unchanged for catalog-linked products (see ProductResource
// changes, TASK-212).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_brands');
    }
};
