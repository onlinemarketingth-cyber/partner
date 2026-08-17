<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-011 Section 3b (TASK-030) — Matrix payout is keyed by LEVEL (how
// many hops up matrix_placements.parent_id from the seller), not by the
// ancestor's own cert tier the way commission_override_rules (Unilevel)
// is. This is a deliberate departure from the Unilevel override shape:
// ADR-006's own textbook-Unilevel research noted "Level 1 override
// might be 5%, Level 2 might be 3%..." as the standard MLM per-level
// pattern, which fits Matrix (inherently depth-capped, unlike
// Unilevel's uncapped chain) much better than reusing a cert-tier-keyed
// table. level 1 = the seller's immediate matrix parent; level N = N
// hops up, capped at commission_matrix_settings.depth.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_matrix_level_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('level');
            $table->string('rate_type'); // App\Enums\CommissionRateType
            $table->unsignedInteger('rate_value');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'level'], 'commission_matrix_level_rates_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_matrix_level_rates');
    }
};
