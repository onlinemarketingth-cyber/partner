<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2026-09-13 — where the team leader's share comes from, as a company choice.
 *
 * See App\Enums\CommissionOverrideMode for the three answers and the worked
 * example that made the owner ask for this.
 *
 * 'additive' IS TODAY'S BEHAVIOUR, written out loud. Unilevel has always paid
 * the leader on top of the seller's commission, so every existing company gets
 * the mode it was already running and not one payout changes. Nothing about
 * the two deduct modes is opt-out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('commission_override_mode')
                ->default('additive')
                ->after('commission_basis');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('commission_override_mode');
        });
    }
};
