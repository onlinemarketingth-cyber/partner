<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR-007 follow-up (human-requested 2026-07-20): spec_value's Store/
// UpdateProductSpecRequest validation already allowed up to 2000 chars,
// but the column was still VARCHAR(255) — inserts between 256-2000 chars
// passed validation yet failed at the DB layer. Widened to TEXT so the
// column matches the validation contract and supports the new textarea
// input. doctrine/dbal is not installed in this project, so this uses a
// raw ALTER TABLE (DDL, not a business-logic query — Section 6's "no raw
// SQL" rule targets query building, not schema migrations) instead of
// Schema::table(...)->change(), which requires doctrine/dbal to read the
// existing column definition.
//
// Live-run fix (2026-07-22, surfaced by `php artisan test --filter=Academy`
// — phpunit.xml runs the test DB on SQLite, see the identical class of bug
// documented in 2026_07_14_130000's own comment trail): `MODIFY` is
// MySQL-only syntax, so this broke every RefreshDatabase test at migration
// time on SQLite. Unlike that commission_ledger case, this one needs no
// shadow-table rebuild at all: SQLite has no enforced column-length/type
// affinity for VARCHAR vs TEXT (it stores both as dynamically-typed TEXT
// regardless of the declared length), and there's no FK/unique constraint
// on this column to preserve — so on SQLite this migration is simply a
// no-op; MySQL's path is untouched.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE product_specs MODIFY spec_value TEXT NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE product_specs MODIFY spec_value VARCHAR(255) NOT NULL');
    }
};
