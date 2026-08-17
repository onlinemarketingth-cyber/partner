<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-150 / ADR-030 §2.1 + §2.2 — the cutover.
 *
 *   module_lesson_quiz_questions.module_lesson_id  →  .quiz_id
 *
 * and, per §2.2 (a human decision, not an implementation detail): "each
 * lesson that has questions gets ONE quiz created for it, named after the
 * lesson, with its questions moved across and `module_lessons.quiz_id` set.
 * No data is lost, no admin has to do anything, and **every existing lesson
 * behaves exactly as it did the moment before**."
 *
 * That last clause is the acceptance criterion, and QuizLibraryMigrationTest
 * asserts it by running this migration over a fixture built in the OLD shape
 * and then taking the quiz through the ADR-029 grading endpoint.
 *
 * =====================================================================
 * WHY A FOUR-TABLE DANCE INSTEAD OF `dropColumn('module_lesson_id')`
 * =====================================================================
 *
 * The obvious version — add `quiz_id`, backfill, drop `module_lesson_id` —
 * cannot work on both drivers:
 *
 *   - SQLite (the test suite) refuses `ALTER TABLE ... DROP COLUMN` for a
 *     column that is named in a FOREIGN KEY constraint or in an index.
 *     `module_lesson_id` is both. And `dropForeign()` is a documented no-op
 *     on SQLite (SQLiteGrammar::compileDropForeign compiles to nothing), so
 *     the constraint cannot be removed first either.
 *   - This project has already been burned twice by exactly this class of
 *     ALTER on SQLite (see 2026_07_14_130000 and 2026_08_14_090500's comment
 *     trails — one of them broke EVERY feature test in the suite).
 *
 * So this follows the project's established remedy for "retarget a foreign
 * key": create a shadow with the final shape, copy every row across with its
 * id preserved, drop the original, rename the shadow into place
 * (2026_07_22_090300 did the same for module_completions).
 *
 * **The options table is dragged into the dance for one specific reason.**
 * `module_lesson_quiz_options.module_lesson_quiz_question_id` is
 * `ON DELETE CASCADE`. On SQLite, `DROP TABLE` performs an implicit DELETE
 * of every row first, and that DELETE fires foreign key actions — so
 * dropping the questions table with options still pointing at it would
 * CASCADE-DELETE EVERY OPTION IN THE DATABASE, silently, and the "lossless"
 * promise of §2.2 would be a lie. `Schema::disableForeignKeyConstraints()`
 * is not a defence: `PRAGMA foreign_keys` is a documented no-op inside a
 * transaction, and SQLite runs each migration inside one.
 *
 * Hence: park the options in a staging table with NO foreign key, drop the
 * child, then the parent, rename, and rebuild the options table exactly as
 * 2026_07_22_090200 created it. Every step is a plain CREATE / INSERT /
 * DROP / RENAME that behaves identically on MySQL 8 and SQLite. Nothing
 * relies on FK enforcement being switched off, because at no point does a
 * live child row point at a table being dropped.
 *
 * Cosmetic MySQL side effect, recorded so it is not mistaken for a bug: the
 * renamed table keeps the FK constraint NAMES it was created with
 * (`mlqq_adr030_shadow_company_id_foreign`). The constraints themselves are
 * correct; only the labels carry the shadow's name.
 *
 * Safe on an empty database — every loop below simply iterates nothing.
 */
return new class extends Migration
{
    private const SHADOW_QUESTIONS = 'mlqq_adr030_shadow';

    private const SHADOW_OPTIONS = 'mlqo_adr030_shadow';

    /** Rows copied per INSERT — the "chunked" part of a chunked backfill. */
    private const CHUNK = 500;

    public function up(): void
    {
        // Defensive, same idiom as 2026_07_22_090200: a half-finished run on
        // MySQL (where DDL is not transactional) leaves shadows behind and
        // the retry would otherwise die on "table already exists".
        $this->dropShadows();

        $this->createOptionsShadow();
        $this->copyOptions('module_lesson_quiz_options', self::SHADOW_OPTIONS);

        Schema::create(self::SHADOW_QUESTIONS, function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // ADR-030 §2.1 — the questions now hang off the QUIZ. Cascade
            // matches the old behaviour exactly: deleting the owner deletes
            // its questions (and, through them, their options).
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->text('question_text');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            // Named for the FINAL table, not the shadow: on SQLite an index
            // keeps its name through a table rename, so naming it correctly
            // now is the only way to end up with a sanely named index.
            $table->index(['company_id', 'quiz_id', 'sort_order'], 'module_lesson_quiz_questions_quiz_sort_idx');
        });

        $movedQuestions = $this->backfillQuizzesAndCopyQuestions();

        /*
         * The losslessness guarantee of §2.2, asserted rather than assumed.
         * If a single question failed to find a quiz we abort loudly instead
         * of dropping the original table over the top of a partial copy —
         * a silently emptied quiz is precisely the failure mode ADR-030 §3
         * warns about.
         */
        $original = DB::table('module_lesson_quiz_questions')->count();

        if ($movedQuestions !== $original) {
            throw new RuntimeException(
                "ADR-030 migration aborted: {$original} quiz questions exist but only {$movedQuestions} were carried across."
            );
        }

        // Child first, then parent — see the class docblock. After this line
        // no live row references the questions table.
        Schema::drop('module_lesson_quiz_options');
        Schema::drop('module_lesson_quiz_questions');
        Schema::rename(self::SHADOW_QUESTIONS, 'module_lesson_quiz_questions');

        $this->createOptionsTable();
        $this->copyOptions(self::SHADOW_OPTIONS, 'module_lesson_quiz_options');

        Schema::drop(self::SHADOW_OPTIONS);
    }

    /**
     * The reverse cutover: questions go back to hanging off the lesson.
     *
     * LOSSY IN ONE DIRECTION, on purpose and only where the old schema
     * genuinely cannot hold the data: a question belonging to a quiz that is
     * attached to NO lesson (a library quiz authored after ADR-030 shipped)
     * has no `module_lesson_id` to be given, and that column is NOT NULL.
     * Those questions are dropped. Everything that was migrated by up() —
     * i.e. everything that existed before ADR-030 — comes back intact, which
     * is what "reversible" has to mean here.
     *
     * The `quizzes` ROWS are deliberately left alone (ADR-030 §3: "do not
     * auto-delete anything an admin typed"); only the links are cleared. The
     * table itself is dropped one migration further back, by
     * 2026_08_24_090000's own down().
     */
    public function down(): void
    {
        $this->dropShadows();

        $this->createOptionsShadow();
        $this->copyOptions('module_lesson_quiz_options', self::SHADOW_OPTIONS);

        Schema::create(self::SHADOW_QUESTIONS, function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_lesson_id')->constrained()->cascadeOnDelete();
            $table->text('question_text');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['company_id', 'module_lesson_id', 'sort_order'], 'module_lesson_quiz_questions_lesson_sort_idx');
        });

        // quiz_id => module_lesson_id. Only ATTACHED quizzes appear, which
        // is exactly the set that is representable in the old shape.
        $lessonIdByQuizId = DB::table('module_lessons')
            ->whereNotNull('quiz_id')
            ->pluck('id', 'quiz_id');

        DB::table('module_lesson_quiz_questions')
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($questions) use ($lessonIdByQuizId) {
                $rows = [];

                foreach ($questions as $question) {
                    if (! isset($lessonIdByQuizId[$question->quiz_id])) {
                        continue; // library-only quiz — unrepresentable, see the docblock.
                    }

                    $rows[] = [
                        'id' => $question->id,
                        'company_id' => $question->company_id,
                        'module_lesson_id' => $lessonIdByQuizId[$question->quiz_id],
                        'question_text' => $question->question_text,
                        'sort_order' => $question->sort_order,
                        'created_at' => $question->created_at,
                        'updated_at' => $question->updated_at,
                    ];
                }

                if ($rows !== []) {
                    DB::table(self::SHADOW_QUESTIONS)->insert($rows);
                }
            });

        Schema::drop('module_lesson_quiz_options');
        Schema::drop('module_lesson_quiz_questions');
        Schema::rename(self::SHADOW_QUESTIONS, 'module_lesson_quiz_questions');

        $this->createOptionsTable();

        // Options whose question did not survive the round trip would
        // violate the recreated FK, so they are filtered out here rather
        // than left to blow up the INSERT.
        $survivingQuestionIds = DB::table('module_lesson_quiz_questions')->pluck('id')->flip();

        DB::table(self::SHADOW_OPTIONS)
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($options) use ($survivingQuestionIds) {
                $rows = [];

                foreach ($options as $option) {
                    if (! isset($survivingQuestionIds[$option->module_lesson_quiz_question_id])) {
                        continue;
                    }

                    $rows[] = (array) $option;
                }

                if ($rows !== []) {
                    DB::table('module_lesson_quiz_options')->insert($rows);
                }
            });

        Schema::drop(self::SHADOW_OPTIONS);

        DB::table('module_lessons')->update(['quiz_id' => null]);
    }

    /**
     * ADR-030 §2.2 — one quiz per lesson that has questions, titled after
     * the lesson, per company (`company_id` is copied from the LESSON, never
     * guessed — BR-6/§5 rule 1).
     *
     * Chunked over the lessons rather than the questions: the unit of work
     * is "a lesson gets a quiz", and chunkById keeps the driver from holding
     * every lesson in memory on a large tenant.
     *
     * Soft-deleted lessons are INCLUDED (there is no `whereNull('deleted_at')`
     * here). Their questions are data too, and losing them would mean a
     * restored lesson came back with an empty quiz.
     *
     * @return int how many question rows were carried across
     */
    private function backfillQuizzesAndCopyQuestions(): int
    {
        $now = now();
        $moved = 0;

        DB::table('module_lessons')
            ->select(['id', 'company_id', 'title'])
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('module_lesson_quiz_questions')
                    ->whereColumn('module_lesson_quiz_questions.module_lesson_id', 'module_lessons.id');
            })
            ->orderBy('id')
            ->chunkById(200, function ($lessons) use ($now, &$moved) {
                foreach ($lessons as $lesson) {
                    $quizId = DB::table('quizzes')->insertGetId([
                        'company_id' => $lesson->company_id,
                        // "named after the lesson" (§2.2). Both columns are
                        // string(255), so nothing can be truncated here.
                        'title' => $lesson->title,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    // The UNIQUE index makes this the ONE place a lesson can
                    // acquire a quiz id; a second lesson claiming the same
                    // id would fail at the database (ADR-030 §2.1).
                    DB::table('module_lessons')
                        ->where('id', $lesson->id)
                        ->update(['quiz_id' => $quizId]);

                    DB::table('module_lesson_quiz_questions')
                        ->where('module_lesson_id', $lesson->id)
                        ->orderBy('id')
                        ->chunk(self::CHUNK, function ($questions) use ($quizId, &$moved) {
                            $rows = [];

                            foreach ($questions as $question) {
                                $rows[] = [
                                    // id preserved: options point at it, and
                                    // so does anything an admin bookmarked.
                                    'id' => $question->id,
                                    'company_id' => $question->company_id,
                                    'quiz_id' => $quizId,
                                    'question_text' => $question->question_text,
                                    'sort_order' => $question->sort_order,
                                    'created_at' => $question->created_at,
                                    'updated_at' => $question->updated_at,
                                ];
                            }

                            if ($rows !== []) {
                                DB::table(self::SHADOW_QUESTIONS)->insert($rows);
                                $moved += count($rows);
                            }
                        });
                }
            });

        return $moved;
    }

    /**
     * A staging copy of the options with NO foreign key — the whole point:
     * while it holds the data, nothing in the database references the
     * questions table, so that table can be dropped without cascading.
     */
    private function createOptionsShadow(): void
    {
        Schema::create(self::SHADOW_OPTIONS, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('module_lesson_quiz_question_id');
            $table->string('option_text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * `module_lesson_quiz_options`, recreated EXACTLY as
     * 2026_07_22_090200 created it — same columns, same short FK name
     * (MySQL's 64-char identifier limit rejects the auto-generated one),
     * same index name.
     */
    private function createOptionsTable(): void
    {
        Schema::create('module_lesson_quiz_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
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

    /** Row-for-row copy, ids preserved, in chunks. */
    private function copyOptions(string $from, string $to): void
    {
        DB::table($from)->orderBy('id')->chunk(self::CHUNK, function ($options) use ($to) {
            DB::table($to)->insert(array_map(fn ($option) => (array) $option, $options->all()));
        });
    }

    private function dropShadows(): void
    {
        Schema::dropIfExists(self::SHADOW_OPTIONS);
        Schema::dropIfExists(self::SHADOW_QUESTIONS);
    }
};
