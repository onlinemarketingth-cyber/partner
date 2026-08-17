<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-006 Round 4: one row per agent per matching-cycle run under a
// Binary plan — snapshots the left/right volume seen, how much matched
// at commission_binary_settings' rate, and how much carried over
// unmatched. commission_ledger_id links to the resulting payout row
// (nullable: a cycle with zero matched volume creates no ledger row,
// per BR-4's "never a $0 row" philosophy already established in
// TASK-025's spec). Schema only — no job produces these rows yet
// (Binary is "under development", human decision 2026-07-14).
//
// Deliberately references commission_ledger (already exists) here;
// the reverse link (commission_ledger.source_binary_cycle_id back to
// this table) is added in a later migration once this table exists —
// see 2026_07_14_200000_add_hierarchy_fields_to_commission_ledger_table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('binary_matching_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('left_volume_satang'); // BR-3, snapshot at cycle time
            $table->unsignedBigInteger('right_volume_satang');
            $table->unsignedBigInteger('matched_volume_satang');
            $table->unsignedBigInteger('unmatched_carried_satang')->default(0);
            $table->foreignId('commission_ledger_id')->nullable()
                ->constrained('commission_ledger')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'agent_id', 'period_start'], 'binary_matching_cycles_agent_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('binary_matching_cycles');
    }
};
