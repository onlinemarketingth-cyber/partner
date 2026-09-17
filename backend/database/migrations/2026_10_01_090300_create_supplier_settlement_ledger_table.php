<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-16 — WHAT WE OWE A SUPPLIER, ONE ROW PER SALE.
 *
 * Owner: "คือคุณต้องแยก DB ออกมาเลยครับ สำหรับ supplier_company_id และไม่ใช่
 * ค่าคอม จะเป็น ราคาขาย−ค่าคอม−GP ถึง supplier_company_id ถึงเบิกได้".
 *
 * ── WHY A SEPARATE TABLE AND NOT commission_ledger ──
 *
 * The owner's instruction, and it is the right call for two reasons that
 * outlive the instruction:
 *
 *   1. commission_ledger is keyed to `agent_id`, a PERSON. This money is owed
 *      to a COMPANY. Bolting a nullable company column onto it would make
 *      every existing query ask "and which kind of row is this?" forever.
 *   2. BR-4 makes commission_ledger immutable, and every commission report in
 *      the system reads it. A second kind of money living there means every
 *      one of those reports has to learn to filter it out — and the day one
 *      of them forgets is the day the commission figures are wrong with
 *      nothing to show for it.
 *
 * This table is immutable in exactly the same way, for exactly the same
 * reason: the numbers on a row are the arithmetic that produced a payment, and
 * arithmetic that can be edited after the fact cannot be reconciled.
 *
 * ── WHY EVERY FIGURE IS SNAPSHOTTED ──
 *
 * `gp_mode_at_time`, `gp_value_at_time` and `wht_rate_at_time` are copies of
 * settings that may change tomorrow. A row has to be able to explain its own
 * amount in two years without reading any current configuration — the same
 * discipline commission_ledger's `*_at_time` columns exist for. Renegotiating
 * a GP must not silently rewrite what we owed last quarter.
 *
 * ── amount_satang IS SIGNED, AND THAT IS THE POINT ──
 *
 * Owner ruling: if commission + GP exceeds the sale price, "supplier เป็นผู้
 * รับผิดชอบ". So the amount genuinely goes negative, the supplier carries it,
 * and it nets off against their other sales. An unsigned column — or a
 * `max(0, …)` anywhere in the service — would quietly move that loss onto us
 * and leave nothing on any screen to show it had happened.
 *
 * ── released_at IS NOT created_at ──
 *
 * The row is written the moment the order is paid, always. `released_at` is
 * when the money becomes withdrawable, which the supplier's deal decides
 * (SupplierReleaseTrigger). Keeping them separate is what lets accounting see
 * a true payables figure on any given day instead of one that is only correct
 * once every outstanding parcel has been delivered.
 *
 * ── NO TenantScope ON THE MODEL ──
 *
 * This row belongs to two companies at once: the one that sold (`company_id`)
 * and the one that gets paid (`supplier_company_id`). A global scope would
 * have to pick one and would therefore be wrong for half the queries. Both
 * are indexed and every query says which it means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_settlement_ledger', function (Blueprint $table) {
            $table->id();

            // Who gets paid.
            $table->foreignId('supplier_company_id')->constrained('companies')->cascadeOnDelete();

            // Which of our companies sold it. NOT this row's tenant key — see
            // the class note. Kept because a supplier statement that cannot
            // say which storefront a sale came from is unauditable.
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // One row per order: a second row for the same sale would pay the
            // supplier twice, so the database refuses rather than trusting
            // every future caller to check first.
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // BR-3 — integer satang throughout. Nothing here is ever divided
            // except at the display layer.
            $table->unsignedBigInteger('sale_price_satang_at_time');

            /*
             * Every commission row this order generated, summed — the seller's
             * and every upline override. Owner: "Full ระบบของเราที่เราทำได้เลย".
             *
             * An order routinely produces more than one commission_ledger row.
             * Counting only the seller's would overpay the supplier on every
             * single sale that has an upline, and nothing downstream would
             * notice.
             */
            $table->unsignedBigInteger('commission_satang_at_time');

            $table->string('gp_mode_at_time', 32);
            $table->unsignedBigInteger('gp_value_at_time');
            $table->unsignedBigInteger('gp_satang_at_time');

            // Basis points, snapshotted. NULL = this sale was not withheld
            // against, which is a decision (goods), not a missing setting.
            $table->unsignedInteger('wht_rate_at_time')->nullable();

            // SIGNED. See the class note — the supplier carries a shortfall.
            $table->bigInteger('amount_satang');

            $table->timestamp('released_at')->nullable();
            $table->string('payment_status', 32)->default('pending');

            $table->timestamps();

            // "What do we owe supplier X that is ready to pay?" — the query
            // behind the payout screen, and the only hot one on this table.
            $table->index(['supplier_company_id', 'payment_status', 'released_at'], 'ssl_supplier_status_released_idx');
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_settlement_ledger');
    }
};
