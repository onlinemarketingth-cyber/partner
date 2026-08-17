<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-011 Section 3c (TASK-031) — Generation plan: overrides paid by
// upline GENERATION (1st generation of breakaway legs below the
// selling agent, 2nd generation, etc.) rather than by flat depth.
// Mirrors commission_override_rules' shape exactly (company_id,
// [key column], rate_type, rate_value, date range), keyed by
// generation_number instead of manager_cert_tier_id — same "separate
// rate table per plan type" precedent as commission_matrix_level_rates.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_generation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('generation_number');
            $table->string('rate_type'); // App\Enums\CommissionRateType
            $table->unsignedInteger('rate_value');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'generation_number'], 'commission_generation_rules_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_generation_rules');
    }
};
