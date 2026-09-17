<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-16 — THE DEAL WITH A SUPPLIER COMPANY.
 *
 * Owner: "อีก User Role คือ Company Partner ที่จะนำสินค้าเข้ามาขายในระบบเราได้
 * … จะเป็นค่าสินค้าหัก GP จากเรา".
 *
 * A supplier brings products in, our members sell them, we keep a GP and pay
 * the rest back. Everything about THAT ARRANGEMENT — the GP, when the money
 * becomes withdrawable, the withdrawal floor, the withholding rate — is a
 * term of the deal with one company, so it lives on that company's row, in
 * exactly the shape `commission_*` already uses for the agent-side rules.
 *
 * ── WHY EVERY ONE OF THESE IS NULLABLE WITH NO DEFAULT ──
 *
 * BR-7: this system does not invent business values. A default GP of 0 would
 * mean "we take no margin", which nobody agreed to; a default withholding of
 * 3% would deduct tax from suppliers selling GOODS, where the standard rate
 * is nothing at all. Both are confident, wrong numbers that look like
 * decisions. NULL means nobody has said yet, and the code is required to
 * refuse rather than guess (SupplierSettlementService).
 *
 * ── is_supplier IS EXPLICIT, NOT DERIVED ──
 *
 * "Has at least one product with supplier_company_id = me" would answer the
 * same question most days and answer it wrong on the first day of a new
 * deal — before the first product is loaded, the payout screen needs to be
 * able to list this company and accounting needs to be able to set its terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('is_supplier')->default(false)->after('is_active');

            /*
             * percent_of_sale | percent_of_net | fixed_per_unit — owner's
             * answer was "เต็มรูปแบบ", all three from day one. Stored as a
             * string rather than an enum column for the same reason every
             * other enum in this schema is: adding a fourth mode should be a
             * PHP change, not a table lock.
             */
            $table->string('supplier_gp_mode', 32)->nullable()->after('is_supplier');

            /*
             * Basis points for the two percent modes (30% = 3000), satang for
             * fixed_per_unit. ONE column for both because a row only ever has
             * one mode, and two columns would let a deal carry a percentage
             * and a fixed amount that disagree.
             *
             * Unsigned: a negative GP is us paying the supplier more than the
             * customer paid us, which is not a deal term, it is a typo.
             */
            $table->unsignedBigInteger('supplier_gp_value')->nullable()->after('supplier_gp_mode');

            /*
             * on_payment | on_redeemed | on_delivered — owner: "ตามแต่ละดีล
             * Setup ได้". This gates `released_at` on the ledger row, never
             * whether the row is written: the sale happened either way and
             * accounting must be able to see what we owe from the moment it
             * does. See the ledger migration.
             */
            $table->string('supplier_release_trigger', 32)->nullable()->after('supplier_gp_value');

            /*
             * Mirrors companies.min_withdrawal_satang, including the part that
             * matters: it binds the SUPPLIER asking, never us deciding to
             * settle what we owe. Same reasoning as WithdrawalSource.
             */
            $table->unsignedBigInteger('supplier_min_withdrawal_satang')->nullable()->after('supplier_release_trigger');

            /*
             * Withholding tax, basis points. NULL = do not withhold, and that
             * has to be a thing somebody chose: Thai practice withholds
             * nothing on a sale of goods and 3% on a service fee, and this
             * system carries both kinds of product in one table. A per-product
             * override sits on `products` for exactly that reason.
             */
            $table->unsignedInteger('supplier_wht_rate')->nullable()->after('supplier_min_withdrawal_satang');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'is_supplier',
                'supplier_gp_mode',
                'supplier_gp_value',
                'supplier_release_trigger',
                'supplier_min_withdrawal_satang',
                'supplier_wht_rate',
            ]);
        });
    }
};
