<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-053 / ADR-016 Phase 1 — per-agent, per-period sales/deal/client
// TARGET set by an Admin, powering the personal "goal ring" on the Agent
// home (alongside the XP→Level ring). BR-7: the target VALUE is admin
// data, never hardcoded. BR-3: a money metric (sales_satang) is stored in
// integer satang. Unique per (agent, period, metric) so re-setting a
// target updates the same row.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            // 'YYYY-MM' = monthly. TASK-130 additionally writes 'YYYY' here
            // for a YEARLY target — 4 characters fit in the same column, so
            // that needed no schema change and no new migration; the unique
            // key below keeps the two apart. (Comment only — column unchanged.)
            $table->string('period', 7);
            $table->string('metric'); // App\Enums\TargetMetric — sales_satang | deals | clients
            $table->unsignedBigInteger('target_value'); // satang for sales_satang; a count otherwise
            $table->timestamps();

            $table->unique(['agent_id', 'period', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_targets');
    }
};
