<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BR-4: immutable ledger entry, created once when the trigger condition
// fires (default: Complete Payment stage, unless config specifies
// otherwise). rate_applied/rate_type_applied snapshot the rule at the
// time it fired, so historical reports never need to recompute from
// commission_rules. payment_status/paid_at are the one explicitly
// allowed mutable field.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('referral_id')->constrained('referrals')->restrictOnDelete();
            $table->foreignId('cert_tier_id_at_time')->constrained('cert_tiers')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('rate_type_applied'); // App\Enums\CommissionRateType — snapshot
            $table->unsignedInteger('rate_applied'); // snapshot of commission_rules.rate_value
            $table->unsignedBigInteger('amount_satang'); // BR-3
            $table->string('payment_status')->default('pending'); // App\Enums\PaymentStatus
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'agent_id', 'payment_status'], 'commission_ledger_agent_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_ledger');
    }
};
