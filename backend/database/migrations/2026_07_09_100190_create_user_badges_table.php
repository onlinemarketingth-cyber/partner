<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BR-5 — one row per Agent per badge earned. Append-only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // Agent
            $table->foreignId('badge_id')->constrained('badges')->restrictOnDelete();
            $table->timestamp('earned_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'badge_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_badges');
    }
};
