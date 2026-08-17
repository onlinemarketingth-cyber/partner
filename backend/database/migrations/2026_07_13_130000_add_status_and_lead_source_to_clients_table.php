<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Human request (2026-07-13), following a CRM-standards comparison:
// "Client-level status" (แก้ gap ที่ client ยังไม่มี referral เลยจะไม่
// มีสถานะให้เห็น) + "lead source / ช่องทางที่มา" (วัดประสิทธิภาพช่องทาง
// การตลาด). See App\Enums\ClientStatus for the status vocabulary.
// lead_source is deliberately a free-text string, not a fixed enum —
// the actual channel list isn't finalized/agreed (BR-7), so it's never
// hardcoded; the UI offers common suggestions without enforcing them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('status')->default('new')->after('email');
            $table->string('lead_source')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['status', 'lead_source']);
        });
    }
};
