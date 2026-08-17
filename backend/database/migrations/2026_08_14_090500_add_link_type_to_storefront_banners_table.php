<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-073 — human-confirmed via AskUserQuestion (2026-08-02): a banner
// can now link to a Product (original, still the default), a free-typed
// URL (admin-only input, opens in a new tab), or one of the Agent
// Portal's own in-app routes (whitelisted, in-app navigation). Exactly
// one of product_id / external_url / internal_path is populated,
// selected by link_type — enforced in the Form Requests, not at the DB
// level (no CHECK constraint — keeps this simple and matches how the
// rest of this codebase handles conditional-field validation).
//
// product_id -> NULLABLE now.
//
// Bug fix (2026-08-03, surfaced by `php artisan test`): this originally
// did `DB::statement('ALTER TABLE storefront_banners MODIFY product_id
// BIGINT UNSIGNED NULL')`, on the stated grounds that `->change()` needs
// doctrine/dbal and this project doesn't ship it. That premise was wrong
// twice over: Laravel 11+ implements `change()` natively (doctrine/dbal
// has not been required since Laravel 10), and `MODIFY` is MySQL-only
// syntax. The test suite runs on in-memory SQLite, which parses it as
// `SQLSTATE[HY000] ... near "MODIFY": syntax error` — so this line broke
// EVERY RefreshDatabase feature test in the whole suite, not just the
// storefront ones. Using the schema builder instead is portable: Laravel
// emits MODIFY on MySQL and rebuilds the table on SQLite.
//
// Only `up()` mattered in production (it had already run against MySQL
// before this fix, so nothing re-executes there) — but `down()` carried
// the identical portability bug and is corrected alongside it.
return new class extends Migration
{
    public function up(): void
    {
        // Separate from the add-columns call below on purpose: on SQLite a
        // `change()` rebuilds the table, and keeping that its own step
        // makes the two operations independently debuggable.
        Schema::table('storefront_banners', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->change();
        });

        Schema::table('storefront_banners', function (Blueprint $table) {
            $table->string('link_type')->default('product')->after('product_id');
            $table->string('external_url')->nullable()->after('link_type');
            $table->string('internal_path')->nullable()->after('external_url');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_banners', function (Blueprint $table) {
            $table->dropColumn(['link_type', 'external_url', 'internal_path']);
        });

        // Best-effort revert — will fail if any row was left with a NULL
        // product_id (link_type url/internal) at rollback time; that's
        // intentional (surfaces the data-loss risk instead of silently
        // dropping rows or picking a fake product_id).
        Schema::table('storefront_banners', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable(false)->change();
        });
    }
};
