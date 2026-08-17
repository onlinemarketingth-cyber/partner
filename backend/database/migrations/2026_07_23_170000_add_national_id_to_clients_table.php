<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-049 — Thai national ID (เลขบัตรประชาชน). Sensitive personal data
// (CLAUDE.md §6 PDPA), so `national_id` is 'encrypted' at rest via the
// model cast (same pattern as clients.health_notes and
// users.bank_account_number). An encrypted column can't be searched with
// LIKE/=, so a separate deterministic blind index `national_id_hash`
// (HMAC-SHA256 of the digits-only value, keyed by APP_KEY) is stored
// alongside it — that column is what the /clients search matches on, and
// it only ever supports EXACT lookup (a full 13-digit number), never
// partial. The hash is indexed for fast lookup; it is a one-way HMAC so
// storing it does not re-expose the plaintext.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('national_id')->nullable()->after('email'); // encrypted cast on the model — PDPA §6
            $table->string('national_id_hash', 64)->nullable()->after('national_id');
            $table->index(['company_id', 'national_id_hash'], 'clients_company_national_id_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_company_national_id_hash_idx');
            $table->dropColumn(['national_id', 'national_id_hash']);
        });
    }
};
