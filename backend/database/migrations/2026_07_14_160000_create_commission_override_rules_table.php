<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-025 (Unilevel manager override): same shape as commission_rules,
// but keyed by the MANAGER's own cert tier (ADR-006 decision), not the
// selling agent's — "Override rate basis" round-2 decision. rate_value
// is basis points/satang exactly like commission_rules (BR-2/BR-3,
// never a float).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_override_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manager_cert_tier_id')->constrained('cert_tiers')->restrictOnDelete();
            $table->string('rate_type'); // App\Enums\CommissionRateType
            $table->unsignedInteger('rate_value');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'manager_cert_tier_id'], 'commission_override_rules_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_override_rules');
    }
};
