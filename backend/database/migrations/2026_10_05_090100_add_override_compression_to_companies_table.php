<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compression: does a skipped upline's LEVEL get reused, or spent?
 *
 * ═══ THE TWO THINGS THAT GET CONFUSED ═══
 *
 * The Unilevel walk already skips a manager who has passed no certification
 * (ADR-035's gate) and carries on to the next one up. That is not compression;
 * that is a gate. Compression is the question the gate leaves open: when
 * level 2's manager is skipped, does the person above them get paid level 2's
 * rate, or level 3's?
 *
 * Until now the answer was "level 3's", because the walk counted hops. With
 * one flat rate that distinction was invisible — every level paid the same
 * number, so it made no difference which one you called it. Per-level rates
 * make it the difference between paying 3% and paying 1%, to a real person,
 * into a row BR-4 will not let anybody correct.
 *
 * ═══ WHY A SWITCH AND NOT A DECISION ═══
 *
 * Both answers are real compensation plans and the industry runs both. Which
 * one a company promises its agents is a business rule (BR-7), and nothing
 * here is entitled to pick — so it is a column with a default that preserves
 * exactly what every existing company does today.
 *
 * FALSE = a skipped manager still spends their level. That is the current
 * behaviour, byte for byte, and it is the default for the same reason
 * `commission_basis` defaulted to price: a company that never opts in must
 * compute what it computed before the column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('override_compression')->default(false)->after('max_override_depth');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('override_compression');
        });
    }
};
