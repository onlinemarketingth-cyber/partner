<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BR-4/Section 4.3: commission triggers once, at the moment a referral's
// pipeline stage becomes Complete Payment — the state machine only
// transitions INTO that stage exactly once per referral (no
// self-loop/re-entry there, unlike Ongoing Next Meeting), so "at most
// one commission_ledger row per referral" is the correct invariant for
// the current business rule. A prior migration (create_commission_ledger_table)
// left no unique constraint here at all — CommissionService::recordForReferral()
// already guards this in application code (check-then-create), but a
// DB-level constraint is the belt-and-braces backstop (same philosophy
// as every other "never trust a single layer" decision in this
// codebase). Flag to a human if a future business rule ever needs
// multiple commission events per referral (e.g. renewal commissions) —
// this constraint and the Service's current assumption would both need
// revisiting together.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->unique('referral_id', 'commission_ledger_referral_unique');
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropUnique('commission_ledger_referral_unique');
        });
    }
};
