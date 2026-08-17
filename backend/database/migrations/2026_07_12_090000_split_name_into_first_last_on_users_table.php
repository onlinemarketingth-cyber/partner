<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Human-requested: self-service "edit name/surname" on ProfileSettingsView
// needs two separate fields (see task discussion — human explicitly chose
// "แยกชื่อ + นามสกุล 2 ช่อง" over keeping one combined field).
//
// Design decision (ag-lead, architecture-level, not a BR-7 business value):
// the existing `name` column is KEPT rather than dropped, and is now a
// derived/synced value — User::booted()'s saving() hook recomputes
// `name = trim("{$first_name} {$last_name}")` whenever either is dirty.
// This means every existing read site (UserResource, LeaderboardController,
// audit logs, both frontends' `auth.user.name`, TopNavigation/AdminNavigation,
// initials() helper, etc.) keeps working with zero changes — only the WRITE
// paths (UserService::create/update via StoreUserRequest/UpdateUserRequest,
// and the two new self-service /me/name + seeders/factory) needed updating.
// Chosen over a full first_name+last_name migration across every Resource/
// component to keep blast radius minimal (CLAUDE.md ag-lead "control scope").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
        });

        // Best-effort backfill of existing rows: first word -> first_name,
        // remainder -> last_name (empty string if the original name had no
        // space, e.g. seed accounts like "Somchai"). Never silently drops
        // data — the original `name` column is untouched by this step.
        //
        // Columns are left nullable() at the DB level deliberately (no
        // ->change() step here — that needs doctrine/dbal, which this
        // project doesn't install, see composer.json). "Required" is
        // enforced at the application layer instead (Section 6: Form
        // Requests validate every input) — UpdateNameRequest/
        // StoreUserRequest both mark first_name/last_name as required.
        DB::table('users')->select('id', 'name')->orderBy('id')->cursor()->each(function ($user) {
            $parts = preg_split('/\s+/', trim((string) $user->name), 2);
            DB::table('users')->where('id', $user->id)->update([
                'first_name' => $parts[0] !== '' ? $parts[0] : $user->name,
                'last_name' => $parts[1] ?? '',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
