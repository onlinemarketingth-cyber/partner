<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-006 Round 4: running left/right sales-volume balance per agent
// under a Binary plan — the running total a matching cycle consumes
// from and carries over into. One row per agent (unique company_id +
// agent_id). Schema only — no Service writes/reads this yet (Binary is
// "under development", human decision 2026-07-14).
//
// left_volume_satang/right_volume_satang: BR-3, always integer satang.
// last_cycle_at: when this agent's balance was last consumed by a
// binary_matching_cycles run — lets the (future) cycle job find agents
// with unprocessed volume.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('binary_leg_volumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('left_volume_satang')->default(0);
            $table->unsignedBigInteger('right_volume_satang')->default(0);
            $table->timestamp('last_cycle_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'agent_id'], 'binary_leg_volumes_agent_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('binary_leg_volumes');
    }
};
