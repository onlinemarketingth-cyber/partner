<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-232 — the short-code registry.
 *
 * WHY A REGISTRY AND NOT A COLUMN ON EACH OF THE SIX EXISTING TABLES.
 * Two of the three things this feature must deliver are cross-cutting:
 * "list every link this agent owns" and "list every link this company has
 * out in the world". Spread across six tables those become a six-way UNION
 * that cannot be paginated or sorted honestly, and every new link type
 * makes it a seven-way one. One row per link, in one table, is what makes
 * the dashboards a normal query.
 *
 * WHY THE SIX TABLES ARE NOT TOUCHED. Their long tokens are already out in
 * the world — inside LINE conversations, in customers' emails, printed in
 * posts. Rewriting them would break links that people are holding right
 * now, for no benefit to anyone already holding one. So this table is a
 * SECOND front door: a new, short code that resolves to the same row. The
 * resolvers try this table first and fall back to the legacy token, which
 * means both doors stay open indefinitely and nothing has to be migrated.
 *
 * WHY `target` IS POLYMORPHIC AND NULLABLE. Six different owning models,
 * and one group (CompanyLogin) that legitimately has no row at all because
 * it is derived from `companies.slug`. A nullable morph says that plainly.
 * The alternative — six nullable FK columns, five of them NULL on every
 * row — says the same thing much worse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_links', function (Blueprint $table) {
            $table->id();

            // BR-6 rule 1 — every business table carries company_id, even
            // where it is derivable through the target. Same convention as
            // affiliate_link_clicks, which denormalizes it for the same
            // reason: tenant scoping must not depend on a join being
            // remembered.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // The public code. 32 is far above the 14 the longest group
            // uses — the headroom is for the custom codes a human types
            // (`thailife`), which have no length rule of their own beyond
            // what the request validates.
            $table->string('code', 32)->unique();

            // App\Enums\TrackedLinkGroup. Stored as its string value so a
            // DBA reading the table sees `product_share`, not `3`.
            $table->string('group', 32);

            $table->nullableMorphs('target');

            // Which campaign this link is for — "โพสต์กลุ่ม LINE 20 ส.ค.".
            // Without it, one agent sharing one product to four channels
            // produces four links whose stats are indistinguishable, and
            // the question they actually want answered ("which channel
            // works?") cannot be asked at all.
            $table->string('label')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Denormalized counters, for list screens only. The TRUTH is
            // always tracked_link_visits; these exist so that rendering a
            // page of 50 links is one query rather than 50 COUNT(*)s. They
            // are recomputable from the visit rows at any time, which is
            // the property that makes keeping them safe.
            $table->unsignedInteger('click_count')->default(0);
            $table->unsignedInteger('unique_click_count')->default(0);
            $table->unsignedInteger('conversion_count')->default(0);

            // "Is this link still alive?" — a question nothing in this app
            // could answer before today, because not one of the six tables
            // recorded when a link was last opened.
            $table->timestamp('first_clicked_at')->nullable();
            $table->timestamp('last_clicked_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'group'], 'tracked_links_company_group_idx');
            $table->index(['company_id', 'created_by_user_id'], 'tracked_links_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_links');
    }
};
