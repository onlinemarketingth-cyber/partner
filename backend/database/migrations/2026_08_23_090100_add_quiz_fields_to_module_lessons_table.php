<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-029 §2.4, §2.6 — the two per-lesson quiz knobs.
 *
 * `quiz_pass_percent` — NULLABLE, and null means INHERIT. The resolution
 * chain is
 *
 *     module_lessons.quiz_pass_percent
 *       ?? academy_completion_settings.quiz_pass_percent  (default 80)
 *
 * i.e. the same most-specific-wins shape the codebase already uses three
 * times (commission rule scoping TASK-028, the pipeline template chain
 * CLAUDE.md §4.3, and per-company video/pdf thresholds ADR-028 §4). Both
 * levels are admin-editable, so the pass mark is never a constant in a
 * Service body (BR-7). 80 is the human's STATED default (ADR-029 §2.4),
 * not an invented number, so — as with ADR-028's 80/100 — there is no
 * `TODO: CONFIRM` here.
 *
 * Nullable rather than "default 80 on every row" on purpose: a copied
 * default is a value that silently stops tracking the company setting the
 * day an admin changes it, and there would be no way to tell a lesson that
 * MEANT 80 from one that merely inherited it.
 *
 * `quiz_blocks_completion` — ADR-029 §2.6. When true the lesson is not
 * complete until the quiz is passed, so BR-1's certification path runs
 * through it; when false the quiz is advisory and the attempt is still
 * recorded for the admin. DEFAULT FALSE, which is the only safe default:
 * `module_lesson_quiz_questions` has existed since ADR-009 and companies
 * have already authored questions against lessons that were never gated on
 * them. Defaulting to true would gate every one of those lessons the
 * moment this migration ran — the exact outage ADR-029 §3 forbids.
 *
 * Per-lesson rather than global because the same course legitimately mixes
 * "you must know this" with "here is some background" (ADR-029 §2.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_lessons', function (Blueprint $table) {
            // Whole percents (1..100), enforced in the Form Requests. Not
            // money — BR-3's integer-satang rule is about monetary amounts
            // and does not apply; a tinyint keeps float away from the
            // pass/fail comparison regardless.
            $table->unsignedTinyInteger('quiz_pass_percent')->nullable()->after('page_count');
            $table->boolean('quiz_blocks_completion')->default(false)->after('quiz_pass_percent');
        });
    }

    public function down(): void
    {
        Schema::table('module_lessons', function (Blueprint $table) {
            $table->dropColumn(['quiz_pass_percent', 'quiz_blocks_completion']);
        });
    }
};
