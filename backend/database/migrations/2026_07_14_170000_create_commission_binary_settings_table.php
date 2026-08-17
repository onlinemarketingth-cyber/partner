<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-006 Round 4: per-company Binary plan config. One row per company
// (unique company_id) — Round 3 decision was one plan type per company,
// so one settings row is enough, no per-product/per-tier variation like
// commission_rules has. Schema only — no CommissionService reads this
// yet (Binary is "under development", human decision 2026-07-14).
//
// matched_rate_type/matched_rate_value: rate applied to the MATCHED
// volume each cycle (BR-2/BR-3 — never a float; same
// percentage/basis-points-or-fixed-satang shape as commission_rules,
// reuses App\Enums\CommissionRateType).
// payout_cap_satang: nullable — null means uncapped; BR-7, admin sets
// this, never hardcoded.
// carry_over_unmatched: whether unmatched leg volume rolls into the
// next cycle (true) or is flushed/lost (false) — standard Binary
// mechanic terminology (see ADR-006 Addendum citations).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_binary_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('matched_rate_type'); // App\Enums\CommissionRateType
            $table->unsignedInteger('matched_rate_value');
            $table->string('cycle_frequency')->default('weekly'); // App\Enums\BinaryCycleFrequency
            $table->unsignedBigInteger('payout_cap_satang')->nullable(); // BR-3, null = uncapped
            $table->boolean('carry_over_unmatched')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_binary_settings');
    }
};
