<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-009 — Udemy-style course hierarchy: Module becomes a "Section"
// (a syllabus chapter under a cert tier); the actual content item
// (video/pdf/link, or a quiz — see ModuleContentType::Quiz) that used
// to live directly on `modules` now lives here, one row per lesson,
// many lessons per section. See migration
// 2026_07_22_090300_migrate_modules_to_sections_and_lessons for the
// one-time backfill that wraps every pre-existing Module row into a
// Section + single Lesson.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('content_type'); // App\Enums\ModuleContentType — video|pdf|quiz|link
            $table->string('source_type')->nullable(); // MediaSourceType — video only
            // Nullable (unlike the old `modules.content_ref`, which was
            // required): a content_type=quiz lesson has no content_ref
            // at all, its content lives in module_lesson_quiz_questions.
            $table->string('content_ref')->nullable();
            $table->string('processing_status')->nullable(); // uploaded video only
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('xp_reward')->default(0); // BR-5/BR-7 — seeded, never hardcoded
            $table->boolean('is_published')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'module_id', 'sort_order'], 'module_lessons_module_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_lessons');
    }
};
