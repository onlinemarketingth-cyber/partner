<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-142 / ADR-028 §2.2, §2.3.
 *
 * `is_downloadable` — per-file admin choice (ADR-028 §2.2). Default FALSE,
 * which is both the safer default and the one that keeps the completion
 * gate meaningful: a downloadable file can be read outside the app, so
 * ADR-028 §2.3 makes its completion fall back to the plain button.
 *
 * NOT DRM, and the Admin UI copy must not claim it is (ADR-028 §2.2 /
 * TASK-145 R3): once a browser renders a PDF it holds the bytes. The flag
 * raises friction and records intent — nothing more.
 *
 * `duration_seconds` — the DENOMINATOR of the video completion gate
 * (ADR-028 §2.3). Server-derived only: CompressUploadedVideo probes it
 * with ffprobe. It is deliberately absent from every Form Request, so a
 * Company Admin cannot silently weaken the gate for a whole company by
 * declaring a 40-minute video to be 10 seconds long; the sanctioned,
 * audit-logged escape hatch is the per-learner admin override
 * (ADR-028 §2.3 guard 2), not this column.
 *
 * Nullable on purpose: an EMBEDDED video (someone else's YouTube page) has
 * no duration we can measure, and ffprobe may be missing on a shared host.
 * LessonCompletionGate treats a null duration as "not verifiable" and
 * falls back to the button rather than locking the learner out — see the
 * comment there, it is a deliberate fail-open.
 *
 * `page_count` — the same idea for a PDF lesson, measured with `pdfinfo`
 * (poppler-utils, already an ADR-008 deployment requirement) at upload
 * time. It is what makes the PDF gate un-forgeable: without a
 * server-measured denominator, a client that reports "this document has 1
 * page and I am on page 1" satisfies a 100% rule instantly. Also nullable,
 * for an external-URL PDF or a host without poppler, in which case the
 * gate falls back to the monotonic client-reported total_pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_lessons', function (Blueprint $table) {
            $table->boolean('is_downloadable')->default(false)->after('processing_status');
            $table->unsignedInteger('duration_seconds')->nullable()->after('is_downloadable');
            $table->unsignedInteger('page_count')->nullable()->after('duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('module_lessons', function (Blueprint $table) {
            $table->dropColumn(['is_downloadable', 'duration_seconds', 'page_count']);
        });
    }
};
