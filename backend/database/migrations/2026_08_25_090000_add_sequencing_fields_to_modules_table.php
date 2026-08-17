<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-151 / ADR-031 §2.2, §2.3 — the two Section-level release controls.
 *
 * `enforce_sequential` (§2.2) — when true, lesson *n* in this Section is
 * locked until lesson *n−1* is COMPLETE (ADR-028's earned completion, not
 * "opened"). Scope is within the Section only: the toggle lives on the
 * Section, so it cannot coherently mean anything wider.
 *
 * `drip_days` (§2.3) — NULL means available immediately; N means the whole
 * Section opens N days after the learner's anchor date. Nullable rather
 * than "default 0" for the same reason ADR-029's `quiz_pass_percent` is
 * nullable: 0 and "no drip configured" are different statements, and a
 * copied default is a value that stops meaning anything the day someone
 * reads it back.
 *
 * BOTH DEFAULT TO OFF, and that is the whole deployment story: ADR-031 §2.2
 * says "turning it on is a deliberate act per Section, so no existing
 * course changes behaviour on deploy". Every Section that exists today
 * reads back `enforce_sequential = false, drip_days = null`, and
 * LessonAccessGate answers "unlocked" from two in-memory comparisons —
 * neither the sibling-chain query nor the completion lookup runs at all,
 * so an untouched course cannot behave differently.
 *
 * Deliberately NOT here: an "unlock at a fixed calendar date" column. §2.3
 * decided drip is relative to the LEARNER, not to the course; a fixed date
 * is a different feature and would need its own ADR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->boolean('enforce_sequential')->default(false)->after('is_published');
            // unsignedSmallInteger: 0..65535 days is absurdly more than any
            // real drip and still one byte cheaper than an int. The real
            // sanity bound lives in the Form Requests (BR-7 note: the bound
            // is a sanity ceiling, the VALUE is admin-chosen config).
            $table->unsignedSmallInteger('drip_days')->nullable()->after('enforce_sequential');
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn(['enforce_sequential', 'drip_days']);
        });
    }
};
