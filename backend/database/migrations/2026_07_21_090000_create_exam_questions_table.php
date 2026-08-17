<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Academy Sprint 1 (human-requested 2026-07-21): replaces the
// ERD-001-open-question exam engine placeholder (exams.config, never
// used for real grading) with a real single-correct-answer multiple
// choice question bank. See exam_question_options (companion migration)
// for the answer side. company_id present directly (not just reachable
// via exam_id) per Section 5 rule 1 — every business table gets its own
// TenantScope-enforceable column, matching product_specs/
// product_spec_attachments precedent rather than relying on a join.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->text('question_text');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['company_id', 'exam_id', 'sort_order'], 'exam_questions_exam_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_questions');
    }
};
