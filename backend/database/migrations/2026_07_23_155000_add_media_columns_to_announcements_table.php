<?php

use Illuminate\Database\Migrations\Migration;

// TASK-049 fix (2026-07-23) — NEUTRALISED.
//
// This migration originally added announcements media columns
// (image_path / video_source_type / video_path / video_embed_url), but it
// was timestamped 2026_07_23_155000 — BEFORE
// 2026_07_26_094000_create_announcements_table. On a fresh `migrate`/test
// run (RefreshDatabase replays every migration in order) it therefore ran
// its ALTER before the table existed → "no such table: announcements".
//
// The column adds now live in 2026_07_26_094500_add_media_columns_to_
// announcements_table.php (guarded/idempotent), which correctly runs AFTER
// the CREATE. This file is kept as an intentional no-op rather than deleted
// so that any DB where it was already recorded as run (the dev DB) keeps a
// matching migration file and its history stays consistent.
return new class extends Migration
{
    public function up(): void
    {
        // no-op — see 2026_07_26_094500_add_media_columns_to_announcements_table.php
    }

    public function down(): void
    {
        // no-op
    }
};
