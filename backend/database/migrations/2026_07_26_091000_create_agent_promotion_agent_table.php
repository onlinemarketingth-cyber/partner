<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pivot backing PromotionTargetType::SpecificAgents — only populated
// when agent_promotions.target_type = 'specific_agents'. A relational
// pivot (not a json column) so tenant-isolation checks and "which
// promotions apply to me" lookups can be plain FK joins/queries instead
// of parsing json server-side.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_promotion_agent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_promotion_id')->constrained('agent_promotions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['agent_promotion_id', 'user_id'], 'agent_promotion_agent_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_promotion_agent');
    }
};
