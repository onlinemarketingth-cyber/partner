<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-077 (2026-08-02, human request: "เพิ่มการ setting การแสดง banner
// แบบต่างๆ เช่น เต็มจอ ด้านล่าง และอื่นๆ", human-confirmed via
// AskUserQuestion) — 4 display styles (full_screen/bottom_sheet/
// centered_card/bottom_strip), ONE global value per company (not
// per-announcement — confirmed "ตั้งค่ากลางค่าเดียว"). default matches
// the pre-existing TASK-075 behavior so nothing visually changes for
// companies that never touch this new setting.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcement_settings', function (Blueprint $table) {
            $table->string('display_style')->default('bottom_sheet')->after('repeat_count');
        });
    }

    public function down(): void
    {
        Schema::table('announcement_settings', function (Blueprint $table) {
            $table->dropColumn('display_style');
        });
    }
};
