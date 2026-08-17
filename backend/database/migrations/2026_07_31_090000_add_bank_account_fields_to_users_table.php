<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-044 Phase A — bank payout details, human-confirmed both
// self-service (Profile Settings) AND Company Admin (Manage Agents) may
// write these (see task doc's "Human decisions" section). All 3
// nullable: an agent may exist for a while before ever supplying bank
// info (BR-7-style "not yet finalized per-agent data", not a config
// value, but same "don't force it up front" spirit) — the CSV export
// this unlocks is explicitly designed to proceed with a per-row
// `missing_bank_info` warning flag rather than block on incomplete data.
//
// bank_account_number is the only one of the 3 with real re-identifying/
// financial sensitivity on its own (bank_name and the holder's name are
// not sensitive in isolation) — Section 6 PDPA "at-rest encryption for
// sensitive fields" is satisfied via User's `encrypted` cast, not at the
// DB layer, so this stays a plain nullable string column here (Laravel
// stores the encrypted ciphertext as text; string is wide enough for
// that ciphertext plus a reasonable margin for now — same pattern
// Laravel's own docs use for 'encrypted' cast columns).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->after('phone');
            $table->string('bank_account_number')->nullable()->after('bank_name');
            $table->string('bank_account_holder_name')->nullable()->after('bank_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'bank_account_number', 'bank_account_holder_name']);
        });
    }
};
