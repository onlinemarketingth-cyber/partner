<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-024 (ADR-006): stamped by CommissionService the moment the
// Complete Payment ledger entry fires (Section 4.3), ONLY when the
// matching commission_rules row has a renewal rate configured — NULL
// here means "no renewal ever scheduled for this referral", the exact
// same opt-in default as today for every referral sold before this
// feature existed and every referral whose product/tier never
// configures a renewal rate. ag-dev choice (flagged per TASK-024's own
// spec): a column on `referrals` rather than a new `client_subscriptions`
// table — a referral already models "one agent sold one product to one
// client once," which is exactly what "when does IT renew" needs; a
// separate table would only earn its keep once a referral could have
// multiple overlapping subscriptions, which nothing in this system
// does today.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->date('next_renewal_date')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropColumn('next_renewal_date');
        });
    }
};
