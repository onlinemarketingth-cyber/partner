<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-059 (human-requested 2026-07-30) — Thai national ID for agents,
// same PDPA-sensitive pattern as Client::national_id (TASK-049,
// 2026_07_23_170000_add_national_id_to_clients_table.php): 'encrypted'
// cast at rest + a deterministic blind index `national_id_hash`
// (HMAC-SHA256, digits-only, keyed by APP_KEY) for exact-match search
// only — an encrypted column can't be searched with LIKE/=.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('national_id')->nullable()->after('bank_account_holder_name'); // encrypted cast on the model — PDPA §6
            $table->string('national_id_hash', 64)->nullable()->after('national_id');
            $table->index(['company_id', 'national_id_hash'], 'users_company_national_id_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_company_national_id_hash_idx');
            $table->dropColumn(['national_id', 'national_id_hash']);
        });
    }
};
