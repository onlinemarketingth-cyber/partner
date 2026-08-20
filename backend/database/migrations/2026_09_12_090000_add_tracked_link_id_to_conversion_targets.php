<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-234 — which link produced this signup / order / lead.
 *
 * ── WHY A COLUMN ON EACH TARGET, AND NOT A CONVERSIONS TABLE ──
 *
 * This codebase already answers this exact question twice, the same way:
 * `referrals.affiliate_link_id` (TASK-032) and
 * `users.recruited_via_agent_link_id` (ADR-025). Both are a nullable FK on
 * the converted record. A third mechanism for the third case would leave
 * three places to look, and the next person would have to learn which one
 * applies to what.
 *
 * It is also the more durable shape. `tracked_links.conversion_count` is a
 * cache and can be wrong; these columns are the fact, and the count can be
 * rebuilt from them at any time. The reverse is not true.
 *
 * ── nullOnDelete, NOT cascade ──
 *
 * Deleting a link must never delete an ORDER. Cascade here would mean a
 * cleanup of stale links quietly destroying revenue records, which is the
 * same class of mistake that TASK-236 is fixing on the affiliate side —
 * there, deleting a link takes its whole click history with it. Losing the
 * attribution is survivable; losing the order is not.
 *
 * ── NULLABLE FOREVER ──
 *
 * Every row that exists today gets NULL, and that is the honest value: the
 * link that produced it either predates this feature or does not exist.
 * Nothing may ever backfill a guess here — an order attributed to the
 * wrong agent's link is worse than an order attributed to nobody, because
 * BR-4 pays commission on attribution.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['users', 'orders', 'referrals'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->foreignId('tracked_link_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('tracked_links')
                    ->nullOnDelete();

                // Indexed for the direction the reports actually read it:
                // "everything this link produced". The other direction
                // (one record's link) is a primary-key lookup already.
                $blueprint->index('tracked_link_id', "{$table}_tracked_link_idx");
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex("{$table}_tracked_link_idx");
                $blueprint->dropConstrainedForeignId('tracked_link_id');
            });
        }
    }
};
