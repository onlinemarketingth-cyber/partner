<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BR-1 gate record — a `basic` row here unlocks SWS Referral/Pipeline for
// an Agent. Checked at both API (Policy) and UI (router guard) per BR-1.
// Append-only (created_at only); one row per Agent per cert tier.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // Agent
            $table->foreignId('cert_tier_id')->constrained('cert_tiers')->restrictOnDelete();
            $table->timestamp('passed_at');
            $table->foreignId('exam_attempt_id')->nullable()->constrained('exam_attempts')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'cert_tier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_certifications');
    }
};
