<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TASK-253 / ADR-040 §1 — NULL company_id means "belongs to the platform,
 * every company uses it".
 *
 * ── WHY THIS EXISTS ──
 *
 * ADR-036 expressed "the same product sold by several companies" as one
 * `products` row PER COMPANY joined by a shared catalog item. The human saw
 * the result on 2026-09-05 — eight rows for four products — and rejected it:
 * "ที่คุณทำคือการ Copy ไปไว้อีกบริษัทหนึ่งมันผิดโจทย์". ADR-040 replaces that
 * model with ONE row every company uses, and this migration is its first
 * step.
 *
 * ── THE CONVENTION IS NOT NEW ──
 *
 * `theme_presets.company_id` has meant exactly this since TASK-217, read
 * through SharedOrTenantScope (`company_id = :own OR company_id IS NULL`).
 * Reusing that scope rather than writing a second one matters more than it
 * looks: this is BR-6, the highest-priority rule in the codebase, and the
 * existing one is already tested and already carries the note about why its
 * OR must live inside a nested closure.
 *
 * ── WHY BRANDS AND CATEGORIES TOO, IN THE SAME STEP ──
 *
 * A central product cannot point at a per-company brand or category — there
 * is one row and many companies. It also cannot leave them NULL: ADR-036's
 * Amendment 1 recorded, from a real audit, that `products.category_id` has
 * three NON-DISPLAY readers, and the worst of them is
 * CommissionService::resolveCommissionRule() — a category-scoped rule stops
 * matching, the sale silently falls back to the company default rate, and
 * that WRONG PAYOUT (BR-2) is written to an immutable ledger row (BR-4).
 * So the taxonomy a central product points at has to be central as well.
 *
 * ── NOTHING CHANGES TODAY ──
 *
 * Relaxing NOT NULL cannot alter a single existing row, and no shared row
 * exists yet, so `company_id = :own OR company_id IS NULL` returns exactly
 * what `company_id = :own` returned this morning. The behaviour arrives with
 * the first platform-owned row, which is TASK-255's migration command, run by
 * a person.
 *
 * ->change(), not a shadow-table rebuild: Laravel 12 alters columns natively
 * and its SQLite grammar rebuilds the table itself, preserving foreign keys
 * — see 2026_08_18_120600's docblock for the from-scratch failure that
 * hand-written column mirrors caused in this same table.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'brands', 'product_categories'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                // foreignId() == unsignedBigInteger; ->change() alters the
                // column definition only and leaves the existing FK alone.
                $blueprint->foreignId('company_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        /*
         * Reversible only while no platform-owned row exists. Once one does,
         * putting NOT NULL back would need a company to assign it to — and
         * there is no honest answer to "which one", since the point of the
         * row is that it belongs to all of them.
         */
        foreach (['products', 'brands', 'product_categories'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('company_id')->nullable(false)->change();
            });
        }
    }
};
