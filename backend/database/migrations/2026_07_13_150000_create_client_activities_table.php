<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-015 — Client Activity/Communication Log (human request,
// 2026-07-13, CRM-standards comparison): a record of every call/chat/
// meeting an agent has with a client, independent of the Referral
// pipeline's stage-change log (Section 4.3). follow_up_notified_at is
// owned by TASK-016 (not written by this task) — NULL means "not yet
// notified or no follow-up set".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('logged_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('type');
            $table->text('summary');
            $table->dateTime('occurred_at');
            $table->dateTime('follow_up_at')->nullable();
            $table->dateTime('follow_up_notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_activities');
    }
};
