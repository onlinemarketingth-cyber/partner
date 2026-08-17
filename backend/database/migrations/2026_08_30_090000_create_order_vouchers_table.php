<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-033 (TASK-189) §2.2 — the post-payment service-access voucher,
// analogous to a hotel voucher: one row per PAID order (1:1), minted
// inside OrderService::confirmPayment()'s existing transaction, guarded
// by the same $alreadyClosed idempotency check that protects BR-4
// commission from double-firing on a re-confirm.
//
// usage_quota/expires_at are a SNAPSHOT of product.voucher_usage_quota /
// product.voucher_validity_days at issuance (ADR-033 §2.2/§2.4) — never
// read the product live at redemption time, same reasoning as
// orders.amount_satang snapshotting price (ADR-017).
//
// Deliberately NO `status` column: `active`/`exhausted`/`expired` is
// computed from usage_quota/used_count/expires_at on the model
// (OrderVoucher::status()) so there is no second predicate that can
// drift from the two source facts (ADR-033 §2.2).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            // Unguessable redemption code — same Str::random(40) treatment
            // as orders.public_token (§5 rule 5 IDOR concern).
            $table->string('code', 40)->unique();
            // Snapshot of product.voucher_usage_quota at issuance. Null = unlimited.
            $table->unsignedInteger('usage_quota')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            // order.paid_at + product.voucher_validity_days at issuance. Null = never expires.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_vouchers');
    }
};
