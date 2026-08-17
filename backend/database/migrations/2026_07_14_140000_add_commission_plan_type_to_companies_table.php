<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-006 Round 3/4: one commission plan type per company. Default
// 'unilevel' — every existing company keeps today's behavior
// unchanged (Unilevel via manager_id + commission_override_rules,
// TASK-025). 'binary' is selectable but has no working
// CommissionService logic yet (human decision 2026-07-14: build the
// schema now, mark the UI "อยู่ระหว่างพัฒนา").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('commission_plan_type')->default('unilevel')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('commission_plan_type');
        });
    }
};
