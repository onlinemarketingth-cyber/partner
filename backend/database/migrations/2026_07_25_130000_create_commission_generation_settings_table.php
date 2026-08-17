<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-011 Section 3c (TASK-031) — max_generation_depth is explicitly
// called out as BR-7 in the task spec's acceptance criteria ("correctly
// capped at whatever max-generation-depth is configured") — ag-lead
// judgment call: rather than leave the cap implicit (e.g. "however many
// commission_generation_rules rows happen to exist"), it gets its own
// admin-editable field, same role commission_matrix_settings.depth
// plays for Matrix. One row per company, same singleton shape as every
// other *_settings table in this plan-type family.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_generation_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete()->unique();
            $table->unsignedInteger('max_generation_depth');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_generation_settings');
    }
};
