<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Agent-view IA item 1.4 ("การเสนอ Promotion ให้ Agent") — a targeted,
// time-boxed bonus campaign, distinct from the permanent
// commission_rules config (BR-2). Unlike Badge/GamificationRule,
// company_id is NOT nullable here: a promotion is always authored
// against one company's own agents/products — there is no "platform
// default promotion" concept in the source requirement, so Super Admin
// must pick an explicit company when creating one (enforced in
// StoreAgentPromotionRequest/AgentPromotionService, same
// forcing-pattern as BadgeService::create()).
//
// bonus_type/bonus_value reuse App\Enums\CommissionRateType's exact
// storage convention (basis points for percentage, satang for
// fixed_satang — BR-3) rather than inventing a parallel scheme.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // null = applies to every product the target agents can sell.
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('target_type'); // App\Enums\PromotionTargetType
            $table->foreignId('target_cert_tier_id')->nullable()->constrained('cert_tiers')->restrictOnDelete();
            $table->string('bonus_type'); // App\Enums\CommissionRateType
            $table->unsignedInteger('bonus_value');
            $table->string('status')->default('draft'); // App\Enums\PromotionStatus
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'agent_promotions_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_promotions');
    }
};
