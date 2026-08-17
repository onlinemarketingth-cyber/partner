<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-024 (ADR-006): a second, optional commission rate for annual
// renewals of the same package. NULL renewal_rate_type = "no renewal
// commission configured for this rule" — fully opt-in per (company x
// cert tier x product), never assumed (BR-7). renewal_recurs governs
// whether the renewal repeats every year (true) or fires exactly once
// (false) — admin-configurable per rule (human decision, ADR-006 Round
// 2), not hardcoded.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->string('renewal_rate_type')->nullable()->after('rate_value'); // App\Enums\CommissionRateType
            $table->unsignedInteger('renewal_rate_value')->nullable()->after('renewal_rate_type');
            $table->boolean('renewal_recurs')->default(false)->after('renewal_rate_value');
        });
    }

    public function down(): void
    {
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->dropColumn(['renewal_rate_type', 'renewal_rate_value', 'renewal_recurs']);
        });
    }
};
