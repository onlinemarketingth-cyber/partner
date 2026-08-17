<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-042 §2 (confirmed 2026-07-23): physical-reward fulfillment is the
// priority to support now, hence default 'physical' — existing seeded
// reward_items rows (all effectively physical-style catalog items so
// far) keep their current behavior with no data migration needed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_items', function (Blueprint $table) {
            $table->string('reward_type')->default('physical')->after('description'); // App\Enums\RewardType
        });
    }

    public function down(): void
    {
        Schema::table('reward_items', function (Blueprint $table) {
            $table->dropColumn('reward_type');
        });
    }
};
