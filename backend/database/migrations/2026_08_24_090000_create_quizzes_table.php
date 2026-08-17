<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-150 / ADR-030 §2.1 — the quiz LIBRARY.
 *
 * Until now a quiz had no existence of its own: questions hung directly
 * off a lesson (`module_lesson_quiz_questions.module_lesson_id`, ADR-009),
 * so there was nothing to author in advance and nothing to pick from. This
 * table is the object an admin prepares first and attaches later — possibly
 * a different person, possibly before the lesson exists at all.
 *
 * IT IS A STAGING AREA, NOT A SHARED BANK. ADR-030 §1 records the human's
 * confirmation explicitly, because the usual reason to build a quiz library
 * is reuse and this one forbids it: **one quiz belongs to at most one
 * lesson, forever, until it is explicitly unlinked.** The rule itself lives
 * on the OTHER side of the link — the UNIQUE index on
 * `module_lessons.quiz_id` (next migration) — so nobody can "improve" this
 * into a many-to-many without deleting a database constraint on the way.
 *
 * company_id per §5 rule 1 (BR-6): a quiz belongs to exactly one tenant and
 * is only ever offered to lessons of that same tenant (§2.5).
 *
 * softDeletes because a quiz is authored content — ADR-030 §3: "do not
 * auto-delete anything an admin typed". A soft-deleted quiz is by
 * definition unattached, since §2.4 refuses to delete a linked one at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // No `key`/slug: unlike a pipeline template (ADR-026) nothing
            // looks a quiz up by handle — it is picked from a list by a
            // human. Title is a plain renameable label.
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
            // The library listing is "this company's quizzes, by title"
            // (QuizController::index), which is exactly this index.
            $table->index(['company_id', 'title'], 'quizzes_company_title_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
