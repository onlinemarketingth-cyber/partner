<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-029 §2.3 — the graded end-of-lesson quiz finally has somewhere to
 * record a result.
 *
 * APPEND-ONLY. A row is written once and never edited, the same spirit as
 * BR-4's immutable commission ledger and the existing `exam_attempts`
 * table: an attempt is a statement about something that happened at a
 * point in time, so "correcting" one would be rewriting history rather
 * than recording it. There is deliberately no update/destroy route
 * (ModuleLessonQuizAttemptController exposes index + store only).
 *
 * `score` is the COUNT OF CORRECT ANSWERS, not a percentage — deliberately
 * different from `exam_attempts.score`, which stores a percent. Storing the
 * raw count alongside `total_questions` means an attempt stays readable
 * after an admin edits the question list, and pass/fail is recomputable
 * from the two numbers without a stored float. (Percentages are not money,
 * so BR-3 does not apply either way; there is simply no float anywhere near
 * the comparison.)
 *
 * `passed` is FROZEN at attempt time, not recomputed on read. The pass mark
 * is admin-editable config (ADR-029 §2.4 / BR-7), and re-deriving `passed`
 * later would let raising the threshold silently un-pass a learner who had
 * already cleared a lesson — the same guarantee ADR-029 §3 makes about
 * `module_completions`.
 *
 * ADR-029 §2.5 — unlimited retries, so there is NO unique constraint here:
 * many rows per (user, lesson) is the normal case, and "the admin can see
 * someone who took eleven tries" is a stated feature. Index
 * (user_id, module_lesson_id) from day one: every read of this table is
 * "this learner's attempts at this lesson" (the completion gate) or "this
 * lesson's attempts" (the Admin readout).
 *
 * The learner's CHOSEN ANSWERS are deliberately NOT stored. ADR-029 §4
 * item 2 ("whether an admin should be able to see an individual learner's
 * chosen answers, or only the score") is unresolved and PDPA-adjacent, and
 * a column nobody may read yet is a column that leaks the moment somebody
 * adds a Resource field to it. Score only until asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_lesson_quiz_attempts', function (Blueprint $table) {
            $table->id();
            // §5 rule 1 — every business table carries company_id.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_lesson_id')->constrained()->cascadeOnDelete();

            // Correct-answer count / questions asked at the moment of the
            // attempt. unsignedSmallInteger: a lesson quiz with >65535
            // questions is not a thing anyone is building.
            $table->unsignedSmallInteger('score');
            $table->unsignedSmallInteger('total_questions');

            $table->boolean('passed');
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->index(['user_id', 'module_lesson_id']);
            $table->index('module_lesson_id');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_lesson_quiz_attempts');
    }
};
