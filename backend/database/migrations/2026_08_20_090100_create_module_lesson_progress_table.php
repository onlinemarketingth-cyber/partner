<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-146 / ADR-028 §2.3 — one row per user per lesson.
 *
 * THE `last_*` / `max_*` SPLIT IS THE WHOLE POINT.
 *   - `last_*` exists only so the learner can RESUME where they stopped.
 *   - `max_*`  is what the completion gate reads, and it never decreases.
 * Scrubbing a video backwards or paging back through a PDF must not
 * un-earn progress already made. Conflating the two is the classic bug in
 * this feature (ADR-028 §2.3), so they are separate columns rather than
 * one column with clever write logic.
 *
 * `total_pages` is the client's own report, and it is only a FALLBACK
 * denominator. The authoritative one is `module_lessons.page_count`,
 * measured server-side with `pdfinfo` at upload time. This column is used
 * only when that measurement is unavailable (an external-URL PDF, or a
 * host without poppler-utils), and even then it is monotonic
 * NON-DECREASING in ModuleLessonProgressService: a client reporting FEWER
 * pages than a previous report is either wrong or shrinking its own
 * denominator to reach the threshold early, so the largest count ever seen
 * wins. Reporting MORE pages only makes the gate harder for the reporter.
 *
 * ADR-028 §5 — these rows are now on the path of every lesson open, so
 * index (user_id, module_lesson_id) from day one. The UNIQUE constraint
 * below IS that index (MySQL and SQLite both back a unique constraint with
 * a real B-tree), so a second identical KEY is deliberately not created —
 * it would double the write cost for zero read benefit. The extra
 * module_lesson_id index serves the Admin per-lesson readout (ADR-028 §4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_lesson_progress', function (Blueprint $table) {
            $table->id();
            // §5 rule 1 — every business table carries company_id.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_lesson_id')->constrained()->cascadeOnDelete();

            // content_type = video (ADR-028 §2.3)
            $table->unsignedInteger('last_position_seconds')->nullable();
            $table->unsignedInteger('max_position_seconds')->nullable();

            // content_type = pdf (ADR-028 §2.3)
            $table->unsignedInteger('last_page')->nullable();
            $table->unsignedInteger('max_page')->nullable();
            $table->unsignedInteger('total_pages')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'module_lesson_id']);
            $table->index('module_lesson_id');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_lesson_progress');
    }
};
