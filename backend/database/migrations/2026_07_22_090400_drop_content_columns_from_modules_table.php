<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-009 — final step of the cutover: `modules` is now purely a
// "Section" (syllabus chapter) — its content-item columns moved to
// module_lessons in the previous migration. Plain dropColumn(), no
// nullability/type ALTER involved, so no doctrine/dbal or SQLite
// rebuild concern here (see 2026_07_20_090000's comment for when that
// concern DOES apply).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn(['content_type', 'source_type', 'content_ref', 'processing_status', 'xp_reward']);
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->string('content_type')->nullable()->after('title');
            $table->string('source_type')->nullable()->after('content_type');
            $table->string('content_ref')->nullable()->after('source_type');
            $table->string('processing_status')->nullable()->after('content_ref');
            $table->unsignedInteger('xp_reward')->default(0)->after('processing_status');
        });

        // Best-effort backfill from each Section's first Lesson (by
        // sort_order) — module_lessons still has data at this point in
        // the down() sequence (migrations reverse in order; the prior
        // migration's down(), which deletes module_lessons, runs AFTER
        // this one).
        $firstLessonByModuleId = DB::table('module_lessons')
            ->orderBy('module_id')
            ->orderBy('sort_order')
            ->get()
            ->unique('module_id')
            ->keyBy('module_id');

        foreach ($firstLessonByModuleId as $moduleId => $lesson) {
            DB::table('modules')->where('id', $moduleId)->update([
                'content_type' => $lesson->content_type,
                'source_type' => $lesson->source_type,
                'content_ref' => $lesson->content_ref,
                'processing_status' => $lesson->processing_status,
                'xp_reward' => $lesson->xp_reward,
            ]);
        }
    }
};
