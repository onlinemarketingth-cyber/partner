<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-033 (TASK-189) §2.2 — one row per redemption, an audit trail (who
// redeemed, when, at which free-text "branch") rather than a soft-editable
// log — same immutability spirit as BR-4's commission ledger, though this
// carries no money. `created_at` only (no `updated_at`): a redemption is
// never edited after creation.
//
// company_id carries TenantScope (§5 rule 1) even though the voucher it
// redeems (order_vouchers) does not have its own company_id — the
// redemption is the tenant-scoped fact, resolved through
// VoucherRedemptionService against $voucher->order->company_id (ADR-033
// §2.1/§2.2 — no branches table, no company_id on order_vouchers itself).
//
// redeemed_at_branch is a plain nullable STRING, not a `branches` FK —
// ADR-033 §2.1: human decision 2 ("สาขาไหนก็ได้") means nothing enforces
// branch-matching, so there is no rule that needs a structured entity yet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('redeemed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('redeemed_at_branch')->nullable();
            $table->timestamp('redeemed_at');
            // created_at ONLY — no updated_at (see docblock above).
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'order_voucher_id'], 'voucher_redemptions_company_voucher_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_redemptions');
    }
};
