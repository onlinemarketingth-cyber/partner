<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-02 — MOB-10. One row per phone that may receive push
 * notifications for a user.
 *
 * ── WHY `token` IS TEXT AND UNIQUENESS LIVES ON `token_hash` ──
 *
 * The API accepts a token of up to 4096 characters (FCM does not promise a
 * length, and a token we truncate is a token that silently never delivers).
 * MySQL cannot put a UNIQUE index on a column that long — InnoDB's key limit
 * is 3072 bytes, which is 768 utf8mb4 characters. So the token itself is
 * stored as TEXT and its SHA-256 is the unique key. Every lookup goes through
 * the hash (DeviceToken::hashToken), never a scan of the text column.
 *
 * ── WHY company_id IS NOT NULL ──
 *
 * BR-6 / §5 rule 1, and also a fact about notifications: `notifications`
 * .company_id is NOT NULL, so a user with no company can never receive one,
 * and a token for them would be a row that is never read. The register
 * endpoint refuses such users rather than storing dead weight.
 *
 * cascadeOnDelete on both foreign keys: a company or user that is really
 * deleted takes its phones with it. A soft-deleted (deactivated) user keeps
 * their rows; the push job skips anyone soft-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('platform', 16); // App\Enums\DevicePlatform
            $table->text('token');
            $table->char('token_hash', 64)->unique();
            $table->string('app_version', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
