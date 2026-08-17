<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-174 (human decision D2, 2026-08-12) — BR-7: whether TASK-026's
// co-agent commission split is live is a per-company admin decision, not a
// platform config and not a deploy. Same "one optional row per company"
// singleton shape as team_visibility_settings / announcement_settings.
//
// THE DEFAULT IS OFF, AND THE ABSENCE OF A ROW MEANS OFF.
// TASK-174 exists to switch the feature off for the early rollout ("ซ่อน
// ระบบนี้ ทั้ง admin และ frontend"), so a company that has never chosen must
// resolve to disabled — a default of `true` would leave every existing
// tenant still splitting until an admin happened to find this toggle, which
// is exactly the audit problem the task removes. A company turns it back on
// when it is ready (spec §2 D2).
//
// Deliberately NO config/*.php platform default: a single operator-flipped
// value that re-enabled money-splitting for every tenant at once is the
// thing D2 rejected.
//
// NOTE (BR-4, spec §3): this table does not touch referrals.co_agent_id /
// split_percentage and never will. The data an agent entered stays exactly
// where it is; the switch only stops it being READ at calculation time
// (CommissionService::recordDirectSale()), so the capability is reversible.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_split_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            // The whole feature, in one flag. Off = a stored co_agent_id is
            // never read when the BR-4 ledger row is written, and the write
            // endpoints refuse outright (spec §4 — "hiding a button while
            // the endpoint still accepts the request is not switching a
            // feature off").
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_split_settings');
    }
};
