<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-151 / ADR-031 §2.4 — "optional lessons: shown, not counted".
 *
 * An optional lesson is displayed and completable like any other, but:
 *
 *   1. It is excluded from every progress DENOMINATOR ("3/5 บท" counts
 *      required lessons only). Counting it would contradict the word — a
 *      learner who skips it would see "4/5" forever and reasonably
 *      conclude the system is broken.
 *   2. It NEVER blocks a sequential chain (§2.2/§2.4). An optional lesson
 *      sitting between two required ones must not gate the next required
 *      one; LessonAccessGate walks past it.
 *
 * DEFAULT FALSE — every lesson that exists today is required, exactly as it
 * is today, and no denominator moves on deploy (ADR-031 non-negotiable 1).
 *
 * Note what this column is NOT: it is not "hidden" and not "unpublished".
 * `is_published` already exists for "the learner should not see this at
 * all"; `is_optional` means "see it, it just is not required of you".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_lessons', function (Blueprint $table) {
            $table->boolean('is_optional')->default(false)->after('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('module_lessons', function (Blueprint $table) {
            $table->dropColumn('is_optional');
        });
    }
};
