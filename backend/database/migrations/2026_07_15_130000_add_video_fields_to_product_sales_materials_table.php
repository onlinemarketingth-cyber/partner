<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-007 — sales materials gain video support (upload or embed).
// file_path/mime_type/size_bytes keep their EXACT existing meaning for
// the upload case (no change to existing rows: source_type defaults to
// 'upload' so every pre-existing row backfills correctly). embed_url is
// a new sibling column, only populated when source_type = 'embed' — for
// an embed row, file_path/original_filename/mime_type/size_bytes are
// meaningless so they must become nullable too (enforced as a pair in
// the Service, not the DB — same pattern as every other "pair" in this
// codebase, e.g. TASK-024's renewal fields).
//
// Uses the same driver-conditional approach established this project
// (2026_07_13_120000, 2026_07_14_130000) for making EXISTING NOT-NULL
// columns nullable across both MySQL and SQLite without doctrine/dbal:
// raw MODIFY on MySQL, full table rebuild on SQLite (named
// indexes/uniques, if any, must be (re)created only AFTER the rebuilt
// table is renamed into place — SQLite index names are unique
// DATABASE-WIDE, not per-table). This table has no named index/unique
// beyond the auto-generated FK constraints, which are safe to declare
// inline via ->constrained() during the rebuild (confirmed safe by
// precedent — see 2026_07_14_130000's own comment).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_sales_materials', function (Blueprint $table) {
            $table->string('source_type')->default('upload')->after('uploaded_by_user_id');
            $table->string('embed_url')->nullable()->after('mime_type');
            $table->string('processing_status')->nullable()->after('embed_url');
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildForSqlite(nullable: true);

            return;
        }

        DB::statement('ALTER TABLE product_sales_materials MODIFY file_path VARCHAR(255) NULL');
        DB::statement('ALTER TABLE product_sales_materials MODIFY original_filename VARCHAR(255) NULL');
        DB::statement('ALTER TABLE product_sales_materials MODIFY mime_type VARCHAR(255) NULL');
        DB::statement('ALTER TABLE product_sales_materials MODIFY size_bytes BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // One-shot rebuild straight back to the pre-migration shape —
            // deliberately NOT a dropColumn() followed by a nullable-
            // toggling rebuild (that would drop source_type/embed_url/
            // processing_status before this method's own SELECT could
            // read them across).
            $this->rebuildForSqliteDown();

            return;
        }

        Schema::table('product_sales_materials', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'embed_url', 'processing_status']);
        });

        DB::statement('ALTER TABLE product_sales_materials MODIFY file_path VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE product_sales_materials MODIFY original_filename VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE product_sales_materials MODIFY mime_type VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE product_sales_materials MODIFY size_bytes BIGINT UNSIGNED NOT NULL');
    }

    /**
     * up()'s rebuild: carries the three new columns (already added by
     * the Schema::table() call above, via plain ADD COLUMN — SQLite
     * handles that natively, no rebuild needed for those three) across
     * into a shadow table where file_path/original_filename/mime_type/
     * size_bytes are now nullable.
     */
    private function rebuildForSqlite(bool $nullable): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('product_sales_materials_rebuild_tmp', function (Blueprint $table) use ($nullable) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('source_type')->default('upload');
            $table->string('file_path')->nullable($nullable);
            $table->string('original_filename')->nullable($nullable);
            $table->string('mime_type')->nullable($nullable);
            $table->string('embed_url')->nullable();
            $table->string('processing_status')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable($nullable);
            $table->timestamps();
        });

        $columns = 'id, company_id, product_id, uploaded_by_user_id, source_type, file_path, original_filename, mime_type, embed_url, processing_status, size_bytes, created_at, updated_at';
        DB::statement("INSERT INTO product_sales_materials_rebuild_tmp ({$columns}) SELECT {$columns} FROM product_sales_materials");

        Schema::drop('product_sales_materials');
        Schema::rename('product_sales_materials_rebuild_tmp', 'product_sales_materials');

        Schema::enableForeignKeyConstraints();
    }

    /** down()'s rebuild: drops the three new columns entirely and restores NOT NULL on the original four. */
    private function rebuildForSqliteDown(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('product_sales_materials_rebuild_tmp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });

        $columns = 'id, company_id, product_id, uploaded_by_user_id, file_path, original_filename, mime_type, size_bytes, created_at, updated_at';
        DB::statement("INSERT INTO product_sales_materials_rebuild_tmp ({$columns}) SELECT {$columns} FROM product_sales_materials");

        Schema::drop('product_sales_materials');
        Schema::rename('product_sales_materials_rebuild_tmp', 'product_sales_materials');

        Schema::enableForeignKeyConstraints();
    }
};
