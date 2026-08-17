<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-042 §3 — records EVERY qualifying promotion-bonus event
// immediately, regardless of the parent agent_promotions.payout_timing.
// This is the audit/traceability trail the spec calls for: a
// monthly_batch promotion still gets a row here the instant the
// referral hits Complete Payment, even though its commission_ledger_id/
// paid_at stay null until PayDueAgentPromotionCredits actually pays it
// out later. No repeat-limit/cap column — TASK-042's own grounding
// facts are explicit that "pay once per qualifying referral event,
// unlimited events per agent per promotion" is the confirmed design for
// this pass (a cap is a new field/rule, out of scope).
//
// bonus_amount_satang is snapshotted HERE (not recomputed later by the
// scheduled command) — BR-3/BR-4 spirit: the amount is fixed at the
// moment the qualifying event actually happened (the referral's
// product price + the promotion's rate at that instant), never
// recomputed live from current config when the deferred payout runs.
//
// created_at only (no updated_at) — commission_ledger_id/paid_at DO get
// set once, after creation, by whichever path (immediate or the
// scheduled command) actually pays this credit; that's the one
// allowed mutation on this table (same "payment_status/paid_at is the
// one allowed mutable field" shape as commission_ledger itself), so an
// updated_at column would only ever record that one write and isn't
// worth the extra column — see AgentPromotionCredit::UPDATED_AT = null.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_promotion_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_promotion_id')->constrained('agent_promotions')->restrictOnDelete();
            $table->foreignId('referral_id')->constrained('referrals')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('bonus_amount_satang'); // BR-3 — integer satang, snapshotted at credit time
            $table->foreignId('commission_ledger_id')->nullable()->constrained('commission_ledger')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'agent_promotion_id'], 'agent_promotion_credits_promotion_idx');
            $table->index(['company_id', 'paid_at'], 'agent_promotion_credits_paid_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_promotion_credits');
    }
};
