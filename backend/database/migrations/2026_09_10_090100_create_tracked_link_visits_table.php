<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-232 — one row per time a human opened a tracked link.
 *
 * Shaped after `affiliate_link_clicks` (TASK-032) on purpose rather than
 * designed fresh: that table is the only per-visit log this app has ever
 * had, it works, and a second visit table in a different shape would leave
 * the next person to guess which one is the pattern.
 *
 * WHY THE COUNTS ON `tracked_links` ARE NOT ENOUGH. A running total can
 * answer "how many" and nothing else. Every question that made this
 * feature worth building — did it come from LINE or Facebook, was that 40
 * opens or 4 people opening it 10 times, what hour do people actually
 * read it — needs the individual rows.
 *
 * APPEND-ONLY. `created_at` with no `updated_at`, matching audit_logs,
 * pipeline_stage_logs and voucher_redemptions. Nothing in the application
 * may edit a visit after the fact; a log you can rewrite is not evidence.
 *
 * NO RAW IP, EVER (PDPA / Section 6). `ip_hash` is HMAC-SHA256 keyed by
 * APP_KEY, exactly as AffiliateLinkClickService already does — a bare
 * sha256 over the small IPv4 space is reversible with a precomputed table,
 * so keying it with a secret is what makes the hash actually protective
 * while still letting the same visitor be recognised as the same person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_link_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tracked_link_id')->constrained()->cascadeOnDelete();

            // The event's own timestamp, separate from created_at. House
            // convention (clicked_at / changed_at / redeemed_at): the
            // moment a thing happened and the moment we wrote the row down
            // are different facts, and conflating them costs you the
            // ability to backfill.
            $table->timestamp('visited_at');

            $table->string('ip_hash', 64);
            $table->string('user_agent', 512)->nullable();

            // HOST ONLY, never the full referring URL. "line.me" is the
            // whole of what the reports need; the full URL can carry the
            // contents of a private group chat's link preview, a search
            // query, or someone's email subject line. Storing less is the
            // feature.
            $table->string('referrer_host', 255)->nullable();

            // mobile / tablet / desktop, derived from the user agent.
            $table->string('device_type', 16)->nullable();

            // First visit from this ip_hash for this link on this calendar
            // day. Computed at write time because the alternative — a
            // DISTINCT over the whole visit history — gets slower every
            // day the feature succeeds.
            $table->boolean('is_unique')->default(false);

            $table->timestamp('created_at')->useCurrent();

            $table->index(['tracked_link_id', 'visited_at'], 'tracked_link_visits_link_idx');
            $table->index(['company_id', 'visited_at'], 'tracked_link_visits_company_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_link_visits');
    }
};
