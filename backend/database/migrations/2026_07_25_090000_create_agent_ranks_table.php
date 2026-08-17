<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-011 Section 3c (TASK-031) — Stairstep/Breakaway + Generation both
// need a sales-volume-based "rank" concept, genuinely new to this
// codebase (distinct from CertTier, which gates BR-1 selling rights,
// not commission level). Company-configurable ladder — volume_threshold
// (BR-7, satang) and rate_type/rate_value are never invented here.
//
// rate_type/rate_value: per the human's explicit design decision
// (ADR-011/TASK-031 design question, answered by the human, not
// assumed by ag-lead) — Stairstep/Breakaway pays a RANK-DIFFERENTIAL
// override: an upline earns the difference between their OWN rank's
// rate and their downline's rank's rate on that downline's sale, until
// the downline reaches a rank marked is_breakaway_rank, at which point
// that leg stops paying the former upline entirely (see
// StairstepCommissionService::payDifferentialOverride() for the walk).
// This is why rank itself carries a rate, unlike agent_ranks' original
// task-spec sketch (which only had name/volume_threshold/sort_order) —
// the rate IS the rank's defining commission property under this
// mechanic, same conceptual role commission_matrix_level_rates plays
// for Matrix, just stored on the rank row itself since Stairstep rate
// resolution never needs a separate date-ranged table (a rank's rate
// changes by editing the rank, not by scheduling a future rate change —
// no product/date range dimension was requested for this one).
//
// is_breakaway_rank: boolean per rank (human's explicit choice over
// "top rank in the ladder only") — any rank can be flagged, giving
// admins flexibility over where the ladder actually breaks.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_ranks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('volume_threshold'); // satang, BR-7 — trailing sales volume required to reach this rank
            $table->unsignedInteger('sort_order');
            $table->string('rate_type'); // App\Enums\CommissionRateType
            $table->unsignedInteger('rate_value');
            $table->boolean('is_breakaway_rank')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'sort_order'], 'agent_ranks_ladder_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_ranks');
    }
};
