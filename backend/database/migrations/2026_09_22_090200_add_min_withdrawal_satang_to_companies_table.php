<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-08-27 — the minimum an agent may ask to withdraw, per company.
//
// A configurable value rather than a constant (human decision): the floor
// exists to stop payout fees eating the payout, and what counts as too small
// depends on the company's own transfer costs — which this system does not
// know and should not guess.
//
// NULL means no minimum. That is a real setting, not a missing one: a
// company that wants to allow any amount says so by leaving this empty, and
// the application must not substitute a default of its own invention.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedBigInteger('min_withdrawal_satang')->nullable()->after('name'); // BR-3
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('min_withdrawal_satang');
        });
    }
};
