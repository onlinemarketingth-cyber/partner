<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BR-4.3: "every status change must be recorded in an audit log (who,
// when, from-status -> to-status)." Append-only; a Service must validate
// only forward/sequential transitions (since ADR-026/TASK-133: the
// referral's own pipeline_template, or PipelineStage::defaultSequence()
// for a referral with no template snapshot).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referral_id')->constrained('referrals')->cascadeOnDelete();
            $table->string('from_stage')->nullable();
            $table->string('to_stage');
            $table->foreignId('changed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_stage_logs');
    }
};
