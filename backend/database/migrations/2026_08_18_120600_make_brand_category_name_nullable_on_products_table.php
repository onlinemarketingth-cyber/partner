<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-036 §2/§3 — a catalog-linked product (products.catalog_item_id set)
// gets its name/brand/category from the shared product_catalog_items row
// instead (TASK-212's "effective_" accessors). Its own brand_id/
// category_id/name columns become genuinely unused in that case, so they
// must accept NULL — otherwise linking a product to a catalog item would
// have no way to also clear its now-redundant local copies without
// violating a NOT NULL constraint.
//
// Standalone products (catalog_item_id = null, every existing row today)
// are completely unaffected: the Service layer (TASK-212) still requires
// brand_id/category_id/name whenever catalog_item_id is null — this is a
// column-level relaxation only, the business rule itself is enforced in
// the Form Request/Service, matching ADR-036 §1's "zero blast radius"
// principle exactly (same reasoning as ADR-035's cert_tier_id change).
//
// WHY NOT THE "SQLITE SHADOW-TABLE REBUILD" PATTERN USED ELSEWHERE
// (2026_07_23_100000, 2026_08_18_100000). That pattern hand-mirrors the
// target table's full column list inside the migration, and is only
// correct while that hand-written mirror matches the table's real shape
// AT THIS POINT IN THE MIGRATION CHAIN. Here it did not: this migration
// is timestamped 2026_08_18, but products only gains pipeline_template_id
// (2026_08_21_090200), voucher_usage_quota / voucher_validity_days /
// requires_shipping (2026_08_30_090200), affiliate_override_mode
// (2026_09_01_090000) and commission_rate_type (2026_09_02_090000) LATER
// in the chain. The original mirror was written against the final
// (already fully-migrated) shape, so on a from-scratch SQLite run the
// INSERT ... SELECT referenced columns that did not exist yet and every
// RefreshDatabase test in the suite died at this migration
// ("no such column: voucher_usage_quota" — 1587 failed / 2 passed).
//
// Laravel 12 changes columns natively (no doctrine/dbal since Laravel 11)
// and its SQLite grammar performs the table rebuild itself, preserving
// foreign keys and indexes — verified on a from-scratch migrate: products
// keeps its company_id / brand_id / category_id / catalog_item_id /
// pipeline_template_id foreign keys with their original on-delete
// behaviour. So ->change() is both driver-agnostic (one code path for
// MySQL 8 and SQLite instead of two) and immune to the drift above,
// because there is no hand-written column list to keep in sync.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // foreignId() == unsignedBigInteger; ->change() alters only the
            // column definition, it does not touch the existing FK.
            $table->foreignId('brand_id')->nullable()->change();
            $table->foreignId('category_id')->nullable()->change();
            $table->string('name')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reversible only while no catalog-linked row has actually cleared
        // these columns — the usual caveat for restoring NOT NULL.
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable(false)->change();
            $table->foreignId('category_id')->nullable(false)->change();
            $table->string('name')->nullable(false)->change();
        });
    }
};
