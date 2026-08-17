<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-146 / ADR-028 §4 (RESOLVED, human decision 2026-08-08).
 *
 * BR-7 — the two completion thresholds are business values, so they are
 * admin-editable config, never constants in a Service body. One optional
 * row per company; config/academy.php holds the platform-wide fallback
 * used when a company has no row (AcademyCompletionSettingService).
 *
 * The defaults below (80 / 100) are the human's STATED values, not
 * invented percentages — so, unlike most BR-7 seeds in this codebase,
 * they carry no `TODO: CONFIRM` (ADR-028 §4 says so explicitly).
 *
 * The asymmetry is intentional and documented in ADR-028 §4: PDF at 100%
 * is strict because a skipped page can be the page that matters, while
 * video at 80% tolerates a trailing outro. Do not "fix" it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academy_completion_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            // Percentages, not money — BR-3's integer-satang rule is about
            // monetary amounts and does not apply. Stored as whole
            // percents (tinyint) so there is no float anywhere near the
            // threshold comparison either way.
            $table->unsignedTinyInteger('video_watch_percent')->default(80);
            $table->unsignedTinyInteger('pdf_read_percent')->default(100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academy_completion_settings');
    }
};
