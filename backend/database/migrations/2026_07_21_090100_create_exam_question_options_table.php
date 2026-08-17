<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Companion to exam_questions (see that migration's comment). is_correct
// is the answer key — ExamQuestionResource/ExamResource must NEVER
// expose it to the Agent role (only Company Admin/Super Admin, same
// masking convention as exams.config in ExamResource). Server enforces
// "at most one correct option per question" by auto-un-marking siblings
// whenever an option is saved with is_correct=true (see
// ExamQuestionOptionService) rather than a DB constraint, since that
// invariant spans multiple rows.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_question_id')->constrained()->cascadeOnDelete();
            $table->string('option_text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['company_id', 'exam_question_id', 'sort_order'], 'exam_question_options_question_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_question_options');
    }
};
