<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// TASK-042 §1 (Reward Points ledger) — BR-7 "Option B" confirmed
// 2026-07-23: Reward Points are a currency separate from XP (BR-5),
// decoupled so that spending points on redemption never touches
// xp_ledger and therefore never affects Level/Leaderboard. Every XP
// award still mirrors 1:1 into a Reward Point award — the mirroring
// happens in GamificationService::awardXp() (the single XP funnel),
// not here; this migration only backfills PRE-EXISTING xp_ledger
// history so no agent loses retroactive points earned before this
// feature existed.
//
// Shape deliberately mirrors xp_ledger (company_id, user_id,
// source_type, source_id, created_at only, no updated_at — append-only,
// same as xp_ledger and commission_ledger) plus a nullable
// xp_ledger_id back-reference for traceability (which XP award this
// row mirrors — null would only happen for a future points-only source,
// none exists yet per TASK-042 "out of scope").
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_point_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // Agent
            $table->string('source_type'); // App\Enums\GamificationSourceType — mirrors xp_ledger.source_type
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedInteger('points_awarded');
            $table->foreignId('xp_ledger_id')->nullable()->constrained('xp_ledger')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'user_id'], 'reward_point_ledger_agent_idx');
        });

        // Backfill: mirror every existing xp_ledger row 1:1 so agents
        // don't lose retroactive points earned before this feature
        // existed. Chunked (not a single INSERT ... SELECT) to stay safe
        // on large tables and to run through the query builder rather
        // than raw SQL, per project convention. This is a one-time data
        // migration — Laravel only ever runs a given migration file's
        // up() once (tracked in the migrations table); re-running it
        // would require a rollback first, and down() drops the table
        // entirely, so a fresh up() always backfills into an empty
        // table — it can never double-count.
        DB::table('xp_ledger')->orderBy('id')->chunkById(500, function ($rows) {
            $now = now();
            $mirrored = $rows->map(fn ($row) => [
                'company_id' => $row->company_id,
                'user_id' => $row->user_id,
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
                'points_awarded' => $row->xp_awarded,
                'xp_ledger_id' => $row->id,
                'created_at' => $row->created_at ?? $now,
            ])->all();

            if (! empty($mirrored)) {
                DB::table('reward_point_ledger')->insert($mirrored);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_point_ledger');
    }
};
