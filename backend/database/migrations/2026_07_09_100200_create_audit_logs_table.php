<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Section 6 — generic polymorphic trail for anything affecting money,
// commission, status, certification, or permissions. company_id nullable
// (some actions are system/cross-company, e.g. super_admin operations).
// pipeline_stage_logs is a specialized version of this same concept for
// referral stage changes specifically.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id'], 'audit_logs_auditable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
