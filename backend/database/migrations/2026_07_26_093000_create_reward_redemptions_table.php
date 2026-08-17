<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One redemption request per row. points_spent is a SNAPSHOT of
// reward_items.cost_points at request time — never re-read live —
// same immutable-historical-record discipline as BR-4's
// commission_ledger (a later catalog price change must not rewrite a
// past request). status is the one mutable field, exactly mirroring
// commission_ledger's "payment status is a separate field" pattern.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // requesting Agent
            $table->foreignId('reward_item_id')->constrained('reward_items')->restrictOnDelete();
            $table->unsignedInteger('points_spent');
            $table->string('status')->default('pending'); // App\Enums\RedemptionStatus
            $table->timestamp('requested_at')->useCurrent();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'reward_redemptions_company_status_idx');
            $table->index(['user_id', 'status'], 'reward_redemptions_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_redemptions');
    }
};
