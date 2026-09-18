<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-18 (human: "หลังลบแล้ว อีเมลเดิม… ปลดอีเมลให้สมัครใหม่ได้").
 *
 * ── WHY A COLUMN AND NOT A CLEVERER TRICK ──
 *
 * `users.email` is UNIQUE and that index has always seen soft-deleted
 * rows, which is why removing somebody has until now locked their address
 * away for good: the remedy was กู้คืน, and an admin who did not know that
 * would tell the person to "just sign up again" and watch it fail
 * (AgentRosterView says exactly this to the admin today).
 *
 * Releasing the address means writing a different value into `email`. The
 * original then has to live SOMEWHERE, because กู้คืน has to give it back,
 * and the two places it could otherwise go are both wrong:
 *
 *   * Inside the tombstone address itself ("deleted+7+bell@…") — a parse
 *     waiting to be got wrong, and `email` is capped at 255.
 *   * In `audit_logs` — that table is a record of what happened, read by
 *     humans and retained on its own schedule. Making a restore DEPEND on
 *     a row in it turns the trail into functional state, and the first
 *     person to prune old audit rows would silently break restores.
 *
 * So: one nullable column, written only by UserService::deactivate when
 * the account is provably untouched (AccountActivityProbe::isPristine),
 * read and cleared only by UserService::restore.
 *
 * NOT unique, and deliberately: two different removed accounts may well
 * have held the same address at different times, and a unique index here
 * would make the SECOND removal fail with a constraint error nobody could
 * explain. Not indexed either — nothing ever searches by it; restore
 * reads it from a row it already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_released_from')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_released_from');
        });
    }
};
