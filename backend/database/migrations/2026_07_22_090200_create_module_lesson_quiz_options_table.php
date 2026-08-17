<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-009 — mirrors exam_question_options (2026_07_21_090100) exactly.
// "At most one correct option per question" is enforced by mutual
// exclusion in ModuleLessonQuizOptionService, not a DB constraint —
// same convention as ExamQuestionOptionService.
return new class extends Migration
{
    public function up(): void
    {
        // Defensive: a prior run of this migration on MySQL can leave the
        // table behind even though Laravel marks the migration as failed
        // (CREATE TABLE succeeds, then the FK constraint is added via a
        // separate ALTER TABLE statement that can fail on its own — see
        // the identifier-length fix below). Drop any such leftover before
        // recreating, so re-running `migrate` after a failed attempt
        // doesn't hit "table already exists".
        Schema::dropIfExists('module_lesson_quiz_options');

        Schema::create('module_lesson_quiz_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Explicit short constraint name — MySQL's 64-char identifier
            // limit rejects the auto-generated
            // `module_lesson_quiz_options_module_lesson_quiz_question_id_foreign`
            // (67 chars).
            $table->foreignId('module_lesson_quiz_question_id')
                ->constrained(table: 'module_lesson_quiz_questions', indexName: 'mlqo_question_id_foreign')
                ->cascadeOnDelete();
            $table->string('option_text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['company_id', 'module_lesson_quiz_question_id', 'sort_order'], 'module_lesson_quiz_options_question_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_lesson_quiz_options');
    }
};
