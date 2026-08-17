<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-042 §2 (confirmed 2026-07-23): shipping details are captured at
// the moment the agent submits the redemption request — not pulled from
// a stored profile address — so these live on the redemption row
// itself, never on the User/agent profile. Required only when the
// target RewardItem.reward_type === physical (see
// StoreRewardRedemptionRequest); stay null/unused for digital items.
// tracking_number is separate: Admin-editable any time after Approved
// (see RewardRedemptionService::updateTrackingNumber()), not captured
// by the agent at request time.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_redemptions', function (Blueprint $table) {
            $table->string('shipping_recipient_name')->nullable()->after('points_spent');
            $table->string('shipping_phone')->nullable()->after('shipping_recipient_name');
            $table->text('shipping_address')->nullable()->after('shipping_phone');
            $table->string('tracking_number')->nullable()->after('decision_note');
        });
    }

    public function down(): void
    {
        Schema::table('reward_redemptions', function (Blueprint $table) {
            $table->dropColumn(['shipping_recipient_name', 'shipping_phone', 'shipping_address', 'tracking_number']);
        });
    }
};
