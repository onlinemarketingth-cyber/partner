<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-28 — ADR-049. Who in a company may invite people into their team.
 *
 * On the team settings row because that is the company's "team" screen and a
 * Company Admin may edit it (the companies row is Super Admin only). The
 * default opens recruiting to every agent who has passed Basic, per the
 * owner; a company that wants ADR-025's admin-designated leaders chooses
 * `designated`. A company with no row at all reads the same default
 * (TeamVisibilitySettingService::recruitPolicy()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_visibility_settings', function (Blueprint $table) {
            $table->string('recruit_policy', 32)->default('all_certified')->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('team_visibility_settings', function (Blueprint $table) {
            $table->dropColumn('recruit_policy');
        });
    }
};
