<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-011 Section 3b (TASK-030) — the placement TREE itself, structurally
// distinct from users.manager_id (Unilevel's chain is a deliberate
// appointment; Matrix placement is auto-assigned by spillover — see
// ADR-011's own reasoning) and from users.binary_leg (Binary is exactly
// 2 legs; Matrix is width-many). One row per agent under a Matrix-plan
// company. parent_id is nullable (root of the company's matrix — the
// first agent placed). position is the 0-indexed slot under parent_id
// (0..width-1, width from commission_matrix_settings) — unique per
// (company_id, parent_id, position) so two agents can never occupy the
// same slot.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matrix_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->unique();
            $table->foreignId('parent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['company_id', 'parent_id', 'position'], 'matrix_placements_slot_unique');
            $table->index(['company_id', 'parent_id'], 'matrix_placements_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matrix_placements');
    }
};
