<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Academy — multiple attempts allowed per Agent; latest pass is what
// matters (Service-layer concern). Append-only (created_at only).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // Agent
            $table->foreignId('exam_id')->constrained('exams')->restrictOnDelete();
            $table->unsignedInteger('score');
            $table->boolean('passed');
            $table->timestamp('attempted_at');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempts');
    }
};
