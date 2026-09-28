<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2026-09-26 — "บริษัททดสอบ", and the moment a company stops being one.
 *
 * Owner: during testing a company must be deletable with everything in it —
 * users, commission, all of it — and once real work starts it may only be
 * deleted while it is still empty. Decided per company, not platform-wide,
 * so a real tenant can never be wiped because somebody forgot to flip a
 * global switch before go-live.
 *
 *   is_test       — may this company be wiped whole? Defaults to FALSE, so
 *                   every company that exists today is protected.
 *   went_live_at  — when the flag was switched off. Once set, the flag can
 *                   never be switched back on: going live is one-way, or a
 *                   real company could be relabelled as a test and wiped.
 *
 * See App\Services\Platform\CompanyRemovalService for the rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('is_active');
            $table->timestamp('went_live_at')->nullable()->after('is_test');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['is_test', 'went_live_at']);
        });
    }
};
