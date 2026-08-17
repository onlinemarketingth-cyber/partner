<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Gamification — BR-5, BR-7. Global config table (like cert_tiers) so
// level breakpoints are never hardcoded in a Service.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_thresholds', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('level_number')->unique();
            $table->unsignedInteger('xp_required');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_thresholds');
    }
};
