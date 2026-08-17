<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-011 Section 3b (TASK-030) — Matrix MLM plan type, first genuinely
// new MLM schema added since ADR-006 Round 4's Binary tables (Matrix
// itself was discussed there but explicitly deferred — "Matrix remains
// a documented future option, not built"). One row per company, same
// singleton shape as commission_binary_settings.
//
// width/depth: BR-7, admin-configurable, never hardcoded — e.g. a
// 3-wide x 5-deep matrix. spillover_rule: a fixed vocabulary
// (App\Enums\MatrixSpilloverRule) like BinaryCycleFrequency, not a
// BR-7 business value — currently only 'breadth' (breadth-first fill)
// is actually implemented by MatrixPlacementService (see that class's
// own docblock for why), but the column exists now so a future
// placement algorithm doesn't need another migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_matrix_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete()->unique();
            $table->unsignedInteger('width');
            $table->unsignedInteger('depth');
            $table->string('spillover_rule')->default('breadth'); // App\Enums\MatrixSpilloverRule
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_matrix_settings');
    }
};
