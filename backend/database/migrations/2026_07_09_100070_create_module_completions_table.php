<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Academy — one row per Agent per module. Append-only (created_at only).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // Agent
            $table->foreignId('module_id')->constrained('modules')->restrictOnDelete();
            $table->timestamp('completed_at');
            $table->unsignedInteger('score')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_completions');
    }
};
