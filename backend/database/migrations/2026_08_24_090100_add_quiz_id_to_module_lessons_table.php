<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-150 / ADR-030 §2.1 — **THIS UNIQUE INDEX IS THE ENTIRE FEATURE.**
 *
 *     module_lessons.quiz_id   nullable FK, UNIQUE
 *
 * The human's rule — "a quiz that is linked to a lesson cannot be linked to
 * another" — is enforced HERE, by the database, and not by a Service that a
 * seeder, a console command, an artisan tinker session or two concurrent
 * requests could each walk around. ADR-030 §2.1, verbatim: "Two lessons
 * cannot claim one quiz even under a race, a seeder, or a console command.
 * Validation in the Form Request and Service still exists so the admin gets
 * a 422 instead of a driver error — but the constraint is what makes it
 * true."
 *
 * NULLABLE, and a UNIQUE index ignores NULLs on both MySQL 8 and SQLite —
 * so any number of lessons may have no quiz, while at most one lesson may
 * hold any given quiz id. That is exactly the rule, expressed in one line
 * of schema.
 *
 * `nullOnDelete()` rather than `restrictOnDelete()`, deliberately:
 *
 *   - §2.4 ("a linked quiz cannot be deleted") is enforced in QuizService
 *     with a 422, and quizzes are SOFT-deleted, so the normal path never
 *     reaches this FK at all.
 *   - RESTRICT would look stricter but would break an unrelated, legitimate
 *     operation: deleting a Company cascades to `quizzes` AND to
 *     `module_lessons`, and MySQL does not promise which cascade it
 *     processes first — a RESTRICT here could abort a tenant deletion
 *     halfway through for no business reason.
 *   - So on the one path that can still reach it (a hard `forceDelete` of a
 *     quiz row, or a company teardown) the lesson survives and merely loses
 *     its quiz, instead of the lesson itself being destroyed or the
 *     transaction failing.
 *
 * NOT in ModuleLesson::$fillable on purpose (see the model): the column may
 * only move through QuizService::attach()/detach(), which check §2.4/§2.5
 * and BR-6 first. A lesson create/update payload can never carry it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_lessons', function (Blueprint $table) {
            $table->foreignId('quiz_id')
                ->nullable()
                ->after('quiz_blocks_completion')
                ->constrained('quizzes')
                ->nullOnDelete();

            // ADR-030 §2.1 — the whole rule, in one constraint.
            $table->unique('quiz_id', 'module_lessons_quiz_id_unique');
        });
    }

    /**
     * Driver-aware, and honest about the difference.
     *
     * MySQL (production) rolls back cleanly: drop the FK, drop the unique
     * index, drop the column.
     *
     * SQLite (the test suite) CANNOT drop a column that is named in a
     * foreign key constraint — `ALTER TABLE ... DROP COLUMN` refuses, and
     * `dropForeign` is a documented no-op on that driver (it compiles to
     * nothing; see SQLiteGrammar::compileDropForeign). Rebuilding
     * `module_lessons` to get rid of one column is not worth it: three
     * other tables point at it (module_completions, module_lesson_progress,
     * module_lesson_quiz_attempts), and a rebuild would have to be
     * choreographed around all of them for a rollback path the test suite
     * never takes (RefreshDatabase re-migrates from scratch).
     *
     * So on SQLite the column is emptied and the index dropped, leaving an
     * inert nullable column behind. Stated plainly rather than hidden,
     * because a silent partial rollback is worse than a documented one.
     */
    public function down(): void
    {
        Schema::table('module_lessons', function (Blueprint $table) {
            $table->dropUnique('module_lessons_quiz_id_unique');
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::table('module_lessons')->update(['quiz_id' => null]);

            return;
        }

        Schema::table('module_lessons', function (Blueprint $table) {
            $table->dropForeign(['quiz_id']);
            $table->dropColumn('quiz_id');
        });
    }
};
