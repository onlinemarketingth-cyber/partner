<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BR-2: "Commission rate depends on the agent's cert tier x the package
// sold. Actual rates live in the commission_rules config table — never
// hardcode numbers." rate_value is an integer in all cases: for
// rate_type=percentage it's basis points (500 = 5.00%), for
// rate_type=fixed_satang it's THB cents (BR-3) — never a float.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cert_tier_id')->constrained('cert_tiers')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('rate_type'); // App\Enums\CommissionRateType
            $table->unsignedInteger('rate_value');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'cert_tier_id', 'product_id'], 'commission_rules_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
