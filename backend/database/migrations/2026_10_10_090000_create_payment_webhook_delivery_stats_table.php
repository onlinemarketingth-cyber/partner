<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-29 — how many webhooks each payment account ACCEPTED and how many it
 * REFUSED for a bad signature, per day.
 *
 * Owner: "การตั้งค่า stripe ในบริษัทแยกกันมีปัญหาเรื่อง web hook ไม่ตรงกัน
 * สามารถขึ้นแจ้งเตือนมีปุ่มทดสอบ webhook ได้ไหม". A wrong signing secret
 * fails silently: the charge succeeds, every event is refused as forged, and
 * nothing on any screen says so. Refusals were only ever a log line.
 *
 * Counters, not rows: a refused request is unverified, so its body is never
 * stored, and one row per (account, provider, day) stays bounded however many
 * forged requests somebody sends.
 *
 * owner_key is 'company:<id>' or 'platform' (ADR-050) — a string rather than
 * a nullable company_id, so the unique index holds for the platform too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_delivery_stats', function (Blueprint $table) {
            $table->id();
            $table->string('owner_key', 32);
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 32); // App\Enums\PaymentProvider
            $table->date('day');
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->timestamp('last_accepted_at')->nullable();
            $table->timestamp('last_rejected_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_key', 'provider', 'day'], 'webhook_stats_owner_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_delivery_stats');
    }
};
