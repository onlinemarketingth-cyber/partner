<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Agent-view IA item 1.5 ("การเคลมแต้มแลกของรางวัล") — reward catalog.
// company_id nullable = same "own company override or platform-wide
// default" shape as Badge/GamificationRule (Super Admin may publish a
// shared catalog; Company Admin's own rows only show to their company).
//
// RESOLVED (TASK-042 §1, BR-7 confirmed 2026-07-23 — supersedes the
// original TODO below): cost_points is spent against a dedicated
// reward_point_ledger (see that migration), NOT against xp_ledger.
// Reward Points are earned automatically, mirroring every XP award 1:1,
// but are a currency fully decoupled from XP — redeeming a reward can
// never affect the Leaderboard/Level system (BR-5, Phase 9). See
// RewardRedemptionService::calculateAvailablePoints().
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('cost_points');
            $table->unsignedInteger('stock_quantity')->nullable(); // null = unlimited
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_items');
    }
};
