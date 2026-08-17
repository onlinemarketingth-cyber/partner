<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-011 Section 3c (TASK-031) — one row per company, same singleton
// shape as commission_binary_settings/commission_matrix_settings.
// trailing_window_days: BR-7, admin-configurable (how far back trailing
// sales volume is summed for rank recalculation) — never hardcoded.
// recalculation_frequency: a fixed vocabulary (App\Enums\
// AgentRankRecalculationFrequency), same "not a BR-7 business value"
// treatment as BinaryCycleFrequency/MatrixSpilloverRule — only the
// numbers inside this table are BR-7, not the shape of the enum itself.
// last_recalculated_at lives HERE (company-wide), not per-agent like
// binary_leg_volumes.last_cycle_at — rank recalculation processes every
// agent in the company together on one cadence, there is no per-agent
// due date the way Binary's per-agent accumulated-balance cycle has.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_rank_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete()->unique();
            $table->unsignedInteger('trailing_window_days');
            $table->string('recalculation_frequency'); // App\Enums\AgentRankRecalculationFrequency
            $table->timestamp('last_recalculated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_rank_settings');
    }
};
