<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-076 (2026-08-02, human request: "ระบบ banner ข่าวสารให้เปิดอย่าง
// น้อย 4 ครั้ง ถึงไม่ขึ้น และสามารถกำหนดได้จาก admin") — BR-7: the number
// of times the Agent Portal auto-pops an unseen announcement before it
// stops must be admin-editable config, never hardcoded. Same
// "one optional row per company" shape as video_processing_settings —
// config/announcements.php holds the platform-wide fallback used when a
// company has no row (AnnouncementSettingService::forCompany()).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('repeat_count')->default(4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_settings');
    }
};
