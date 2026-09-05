<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TASK-240 — the two indexes the audit trail is actually read by.
 *
 * The table shipped with one index, `(auditable_type, auditable_id)` — the
 * "show me this record's history" lookup. Every screen that exists reads it
 * the other two ways instead:
 *
 *   "what did this PERSON do"   -> actor_user_id, newest first
 *   "what happened in this COMPANY" -> company_id, newest first
 *
 * COMPOSITE, NOT TWO SINGLE COLUMNS. Every real question carries a time
 * range or is rendered newest-first (the viewer paginates on
 * `orderByDesc('created_at')`), so an index on the id alone still leaves the
 * database sorting every row it matched. With created_at in the index the
 * ordering comes for free and the pagination stays flat as the table grows.
 *
 * WHY NOW: TASK-240 adds `?actor_user_id=`, which turns a column nothing
 * queried into the most-used filter on the screen. Adding the filter without
 * the index would make this table slower for everyone, one audited action at
 * a time, and the symptom would appear months later as "the report page got
 * slow" with nothing in the diff to blame.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['actor_user_id', 'created_at'], 'audit_logs_actor_time_idx');
            $table->index(['company_id', 'created_at'], 'audit_logs_company_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_actor_time_idx');
            $table->dropIndex('audit_logs_company_time_idx');
        });
    }
};
