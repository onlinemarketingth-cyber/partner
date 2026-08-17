<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Human request (2026-07-13): "เวลาที่สะดวกนัดไม่ต้อง validate" —
// preferred_time (one of the SWS Referral fields, CLAUDE.md §2) is no
// longer required. Uses raw SQL rather than Schema::table()->change()
// because doctrine/dbal isn't installed in this project (see
// 2026_07_12_090000_split_name_into_first_last_on_users_table for the
// same constraint/precedent) — MODIFY COLUMN doesn't need it on MySQL.
//
// Bug found + fixed 2026-07-14 (live `php artisan test` run by the
// human surfaced it, human chose "keep raw SQL, no new dependency"
// over adding doctrine/dbal): MySQL's MODIFY syntax doesn't exist on
// SQLite, which is what phpunit.xml actually uses for the test DB
// (DB_CONNECTION=sqlite, :memory:) — every RefreshDatabase test was
// failing at migration time because of this. SQLite has no ALTER
// COLUMN at all, so the fix is the standard SQLite table-rebuild:
// create a shadow table with the corrected schema, copy every row
// across (explicit column lists on both sides so column-order
// differences can't silently misalign data), drop the original,
// rename the shadow into its place. Foreign-key checks are disabled
// for the swap (SQLite would otherwise block dropping a table other
// tables reference) and re-enabled immediately after. MySQL's path is
// completely untouched — this only changes behavior on SQLite.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildForSqlite(nullable: true);

            return;
        }

        DB::statement('ALTER TABLE referrals MODIFY preferred_time DATETIME NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildForSqlite(nullable: false);

            return;
        }

        DB::statement('ALTER TABLE referrals MODIFY preferred_time DATETIME NOT NULL');
    }

    /**
     * Exact column-for-column mirror of
     * 2026_07_09_100130_create_referrals_table's schema (this is the
     * only migration that has ever touched this table besides this
     * one), with only preferred_time's nullability toggled.
     *
     * Live-run fix (2026-07-14, second bug this same day): the first
     * attempt at this rebuild failed with "index referrals_agent_stage_idx
     * already exists" — unlike MySQL, SQLite index names are unique
     * DATABASE-WIDE, not per-table, so creating the shadow table's index
     * while the original `referrals` table (and its identically-named
     * index) still existed collided. Fix: the shadow table is created
     * WITHOUT any named index, data is copied, the OLD table (and its
     * index) is dropped, the shadow is renamed into place, and only
     * THEN is the index (re)created on the now-real `referrals` table —
     * by that point the original index no longer exists, so there's no
     * name collision.
     */
    private function rebuildForSqlite(bool $nullable): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('referrals_rebuild_tmp', function (Blueprint $table) use ($nullable) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('branch');
            $nullable ? $table->dateTime('preferred_time')->nullable() : $table->dateTime('preferred_time');
            $table->string('current_stage');
            $table->unsignedTinyInteger('meeting_number')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();
        });

        $columns = 'id, company_id, client_id, agent_id, product_id, branch, preferred_time, current_stage, meeting_number, submitted_at, created_at, updated_at';
        DB::statement("INSERT INTO referrals_rebuild_tmp ({$columns}) SELECT {$columns} FROM referrals");

        Schema::drop('referrals');
        Schema::rename('referrals_rebuild_tmp', 'referrals');

        Schema::table('referrals', function (Blueprint $table) {
            $table->index(['company_id', 'agent_id', 'current_stage'], 'referrals_agent_stage_idx');
        });

        Schema::enableForeignKeyConstraints();
    }
};
