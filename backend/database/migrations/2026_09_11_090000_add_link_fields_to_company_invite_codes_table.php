<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-233 — turns the company invite CODE into a company signup LINK.
 *
 * ── WHY THIS TABLE NEEDS CHANGING AT ALL ──
 *
 * `company_invite_codes` has existed since ADR-005 and the application has
 * only ever READ it. There is no controller, no service, no policy, no
 * route and no screen that can create one — the sole way a code has ever
 * come into being outside a test factory is an INSERT typed by hand. And
 * it was never a link: a recruit had to reach /register on their own and
 * type the code in.
 *
 * Making it the target of `partner.syncvision.io/c/thailife` changes what
 * the row has to be able to express, in three ways.
 *
 * 1. `expires_at` BECOMES NULLABLE.
 *    The original comment argues, correctly for its time, that a Super
 *    Admin should have to choose an expiry rather than get a baked-in
 *    default. But the thing being created now is printed — on a flyer, a
 *    business card, a sign in a branch office. Paper does not expire, and
 *    a link that dies while the poster is still on the wall is worse than
 *    one that outlives its campaign. NULL means "no expiry" explicitly,
 *    which is different from the request omitting the field: the API still
 *    requires the caller to say which they want (BR-7 — the default is not
 *    ours to invent), it just now has two legal answers instead of one.
 *
 * 2. `max_uses` / `used_count` ARRIVE.
 *    `agent_invite_links` has carried both since ADR-025 §3 and this table
 *    never did, so "the first 50 recruits" was expressible for a team
 *    leader and not for the company. Same column names and same NULL =
 *    unlimited meaning, deliberately, so the two read identically.
 *
 * 3. `code` GETS A LENGTH.
 *    It was an unbounded string because nothing but a human typing it
 *    cared. It is about to be a URL segment, and an unbounded unique index
 *    is also a MySQL row-size problem waiting for the first very long
 *    value. 64 is far past anything a person would choose.
 *
 * DOWN() is deliberately partial and says so: it can restore the columns,
 * but it cannot restore a NOT NULL constraint on `expires_at` while rows
 * exist that legitimately have none. Failing loudly beats silently
 * deleting somebody's permanent signup link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_invite_codes', function (Blueprint $table) {
            $table->dateTime('expires_at')->nullable()->change();
            $table->string('code', 64)->change();

            $table->unsignedInteger('max_uses')->nullable()->after('label');
            $table->unsignedInteger('used_count')->default(0)->after('max_uses');
        });
    }

    public function down(): void
    {
        $permanent = DB::table('company_invite_codes')->whereNull('expires_at')->count();

        if ($permanent > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$permanent} company invite code(s) have no expiry, which the old schema cannot store. ".
                'Give them an expiry, or accept that rolling back deletes working signup links.'
            );
        }

        Schema::table('company_invite_codes', function (Blueprint $table) {
            $table->dropColumn(['max_uses', 'used_count']);
            $table->dateTime('expires_at')->nullable(false)->change();
        });
    }
};
