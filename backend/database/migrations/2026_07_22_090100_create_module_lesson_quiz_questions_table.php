<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-009 — lesson quiz question bank, deliberately identical shape to
// exam_questions (2026_07_21_090000) so ModuleLessonQuizOptionService
// can copy ExamQuestionOptionService's mutual-exclusion logic verbatim.
// Unlike an Exam (BR-1 gate, summative), a lesson quiz is formative —
// it never blocks BR-1, never blocks moving to the next lesson, and
// has no passing_score column: submitting it (any score) is what
// counts as "completing" this lesson (see ModuleLessonQuizAttemptService).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_lesson_quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_lesson_id')->constrained()->cascadeOnDelete();
            $table->text('question_text');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['company_id', 'module_lesson_id', 'sort_order'], 'module_lesson_quiz_questions_lesson_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_lesson_quiz_questions');
    }
};
