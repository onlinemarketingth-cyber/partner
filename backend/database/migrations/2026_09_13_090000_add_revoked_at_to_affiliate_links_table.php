<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-236 — stop "ยกเลิกลิงก์" from destroying its own click history.
 *
 * ── THE BUG ──
 *
 * `AffiliateLinkController::destroy()` calls `$affiliateLink->delete()` —
 * a hard delete. `affiliate_link_clicks.link_id` is `cascadeOnDelete`, so
 * every click that link ever recorded goes with it.
 *
 * Which means an agent tidying up a link they no longer use silently
 * erases the evidence of how well it worked, and the company's own
 * reporting quietly changes shape underneath them. Worse, it is the ONE
 * link type in this application with real per-click history — the other
 * five have a counter or nothing — so the delete destroys the most
 * valuable data in the feature and nothing warns anybody.
 *
 * It is also the only one of the six that hard-deletes at all. Product
 * shares, sales-material shares, agent invites and (since TASK-233)
 * company invite codes all set a `revoked_at` and keep the row. This
 * column brings affiliate links in line with the house rule rather than
 * inventing a new one.
 *
 * ── WHY REVOKING IS THE RIGHT VERB, NOT SOFT-DELETING ──
 *
 * `softDeletes()` would also stop the cascade, but it says "this row is
 * gone, pretend you cannot see it", and every existing query would need a
 * `withTrashed()` to keep reporting on it. `revoked_at` says the true
 * thing: the link is switched off and its history is still yours. That is
 * the same word, the same column name and the same meaning the other five
 * tables already use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_links', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_links', function (Blueprint $table) {
            $table->dropColumn('revoked_at');
        });
    }
};
