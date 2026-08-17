<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-049 fix (2026-07-23) — this migration was originally timestamped
// 2026_07_23_155000, i.e. BEFORE 2026_07_26_094000_create_announcements_table.
// That worked on the dev DB (where the table already existed and only this
// new migration ran) but broke every fresh `migrate` / test run
// (RefreshDatabase replays all migrations in order → this ALTER ran before
// the CREATE → "no such table: announcements"). Renamed to 094500 so it
// always runs AFTER the table exists.
//
// Idempotent guards (Schema::hasColumn) make the rename safe on a DB where
// the old-named migration already applied these columns: this renamed file
// is seen as "pending" and re-runs, but each column is skipped if already
// present — a harmless no-op — rather than erroring with "duplicate column".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            if (! Schema::hasColumn('announcements', 'image_path')) {
                $table->string('image_path')->nullable()->after('expires_at');
            }
            if (! Schema::hasColumn('announcements', 'video_source_type')) {
                $table->string('video_source_type')->nullable()->after('image_path'); // App\Enums\MediaSourceType
            }
            if (! Schema::hasColumn('announcements', 'video_path')) {
                $table->string('video_path')->nullable()->after('video_source_type');
            }
            if (! Schema::hasColumn('announcements', 'video_embed_url')) {
                $table->string('video_embed_url')->nullable()->after('video_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            foreach (['image_path', 'video_source_type', 'video_path', 'video_embed_url'] as $column) {
                if (Schema::hasColumn('announcements', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
