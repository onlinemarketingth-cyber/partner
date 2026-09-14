<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-14 — the deduction mode, per RATE rather than per company.
 *
 * Owner: "การตั้งค่าใน Step ที่ 4 ต้องต่างกันทั้งหมด แต่ตอนนี้เป็นตัวเลือกการ
 * ทำงานแบบอย่างเดียว".
 *
 * `companies.commission_override_mode` (2026-09-26) answered "where does the
 * leader's money come from" once for the whole tenant. That is the right
 * default and the wrong only-answer: the leader rate itself is already scoped
 * product > category > company, and a company that pays 2% on top for its
 * flagship package while the team splits the commission on everything else has
 * a plan, not a contradiction.
 *
 * ── WHY NULLABLE, AND WHY NULL IS NOT 'additive' ──
 *
 * NULL means "use the company's setting", which is a THIRD state and the one
 * almost every row will be in. Defaulting the column to 'additive' would have
 * frozen every existing rate at the value the company happened to hold on the
 * day this migration ran: a company on DeductFromSale would silently start
 * paying its leaders on top, at its own expense, on every rate it had already
 * approved — and BR-4 means those ledger rows can never be corrected.
 *
 * So: null = follow the company (and keep following it when the company
 * changes its mind), a value = this rate was deliberately given its own.
 * CommissionService::overrideModeForRule() is the one place that coalesces.
 *
 * The ledger already snapshots the mode that produced each row
 * (`override_mode_at_time`, 2026_09_26_090100), so history stays readable
 * whichever layer the answer came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_override_rules', function (Blueprint $table) {
            // String rather than a DB enum, exactly as
            // companies.commission_override_mode is: adding a case to
            // App\Enums\CommissionOverrideMode must not require an ALTER on a
            // table that is read on every sale.
            $table->string('override_mode')->nullable()->after('rate_value');
        });
    }

    public function down(): void
    {
        Schema::table('commission_override_rules', function (Blueprint $table) {
            $table->dropColumn('override_mode');
        });
    }
};
