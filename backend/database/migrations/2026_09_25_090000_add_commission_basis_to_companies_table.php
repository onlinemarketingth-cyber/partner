<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2026-09-12 (owner): "ทำแผน PV ... เก็บการคิดแบบ % และ Fix จำนวนเงิน ไว้กับ
 * ค่าคอมปรกติ" — the PV plan is added ALONGSIDE the existing calculation,
 * not in place of it.
 *
 * ── THE DEFAULT IS THE WHOLE SAFETY ARGUMENT ──
 *
 * 'price' is today's behaviour, written out loud. Every existing company
 * gets it, and CommissionService computes byte-identical amounts to the
 * ones it computed yesterday: same base, same rounding, same ledger rows.
 * Nothing about PV is opt-out; a company that never opens the setting
 * never meets it.
 *
 * ── WHY THIS SITS NEXT TO commission_plan_type ──
 *
 * Because it is the same KIND of decision and belongs to the same
 * sentence: "how does this company pay". `commission_plan_type` says who
 * gets paid (Unilevel / Binary / Matrix / …), `commission_basis` says
 * what the percentage is a percentage OF. Both are company-level, both
 * are answered once on step 2 of the settings screen, and putting them in
 * different places would be the first step toward answering them in
 * different places too.
 *
 * A string column and not an enum column, matching commission_plan_type's
 * own choice on this table: MySQL ENUM changes need a table rebuild, and
 * App\Enums\CommissionBasis is the validation boundary in both the Form
 * Request and the model cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('commission_basis')
                ->default('price')
                ->after('commission_plan_type');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('commission_basis');
        });
    }
};
