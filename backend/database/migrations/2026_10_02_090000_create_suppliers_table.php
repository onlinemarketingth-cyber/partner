<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-17 — A SUPPLIER IS ITS OWN THING.
 *
 * Owner, rejecting the first attempt: "เราคุยกันแล้วว่ามันต่างกัน
 * Company Partner = Supplier ต้องแยกจาก Company เดิม แต่คุณเอา UI ไปใส่ที่
 * company เดิมที่เป็นค่าคอม ผิดทั้งหมดเลย".
 *
 * ── WHAT WENT WRONG THE FIRST TIME ──
 *
 * The first cut modelled a supplier as `companies.is_supplier = true` plus a
 * handful of `supplier_*` columns on the same row. That put two different
 * legal and financial relationships in one table:
 *
 *   companies  a TENANT. Sells through us. We pay COMMISSION to their agents.
 *              Carries commission plans, ranks, overrides. Scoped by BR-6 so
 *              its people see their own data and nobody else's.
 *
 *   suppliers  a COUNTERPARTY. Supplies goods we sell. We pay them the SALE
 *              PRICE LESS COMMISSION LESS GP. Carries a GP, a release
 *              trigger, a withdrawal floor, a withholding rate and a bank
 *              account. Sees orders across MANY tenants — precisely the
 *              cross-tenant read BR-6 exists to prevent for a tenant.
 *
 * Not one column means the same thing in both. The flag made the schema
 * unable to answer "is this row a seller or a supplier" without also knowing
 * which question you meant, and it put the deal terms on the screen where
 * commission plans are edited, which is where the owner found them.
 *
 * ── NO company_id HERE, DELIBERATELY ──
 *
 * A supplier is not a tenant and does not belong to one. If a company that
 * sells through us ALSO supplies goods to us, that is two rows in two tables
 * (owner: "ทำตามที่คุณแนะนำ"), because it is two contracts, two bank
 * accounts, two sets of numbers and two logins. A convenience column linking
 * them would be the same conflation wearing a nullable hat, and the first
 * query that joined on it would be back where this started.
 *
 * Consequently this table carries NO TenantScope. It is platform data, like
 * a platform product: only a Super Admin reads or writes it.
 *
 * ── EVERY DEAL TERM IS NULLABLE WITH NO DEFAULT ──
 *
 * BR-7, unchanged from the first cut and still right: a default GP of 0 means
 * "we take no margin", which nobody agreed to, and a default withholding of
 * 3% deducts tax from suppliers of GOODS, where the standard rate is nothing.
 * Both are confident wrong numbers that look like decisions. NULL means
 * nobody has said yet, and SupplierSettlementService is required to refuse
 * rather than guess.
 *
 * A deal negotiated in stages is normal, so a half-filled row SAVES. The
 * payout screen is what refuses to pay an incomplete one, and it says which
 * part is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            // What we call them on our screens.
            $table->string('name');

            /*
             * The name on the contract and on the withholding certificate,
             * which is routinely not the name anybody uses day to day. Kept
             * separate so the working name can be changed without touching
             * what the paperwork says.
             */
            $table->string('legal_name')->nullable();
            $table->string('tax_id', 32)->nullable();

            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_email')->nullable();
            $table->text('address')->nullable();

            /*
             * Inactive rather than deleted is the normal end of a deal: the
             * settlement history has to stay readable and payable. Listing and
             * the product picker filter on this; nothing about money does.
             */
            $table->boolean('is_active')->default(true);

            // ── DEAL TERMS ──────────────────────────────────────────────

            // percent_of_sale | percent_of_net | fixed_per_unit. Owner asked
            // for all three from day one ("เต็มรูปแบบ"). String, not an enum
            // column: a fourth mode should be a PHP change, not a table lock.
            $table->string('gp_mode', 32)->nullable();

            /*
             * Basis points for the two percentage modes (30% = 3000), satang
             * for fixed_per_unit. ONE column for both, because a row only ever
             * has one mode and two columns would let a deal carry a percentage
             * and an amount that disagree.
             *
             * Unsigned: a negative GP is us paying a supplier more than the
             * customer paid us. That is not a deal term, it is a typo.
             */
            $table->unsignedBigInteger('gp_value')->nullable();

            /*
             * on_payment | on_redeemed | on_delivered — owner: "ตามแต่ละดีล
             * Setup ได้". Gates `released_at` on the ledger row, never whether
             * the row is written: the sale happened either way and accounting
             * must see what we owe from the moment it does.
             */
            $table->string('release_trigger', 32)->nullable();

            /*
             * Binds the SUPPLIER asking, never us deciding to settle what we
             * owe — the same asymmetry WithdrawalSource encodes for agents.
             */
            $table->unsignedBigInteger('min_withdrawal_satang')->nullable();

            /*
             * Withholding tax, basis points (300 = 3%). NULL = do not withhold,
             * and that has to be something somebody chose: Thai practice
             * withholds nothing on a sale of goods and 3% on a service fee,
             * and this system carries both kinds of product. A per-product
             * override sits on `products` for exactly that reason.
             */
            $table->unsignedInteger('wht_rate')->nullable();

            // ── WHERE THE MONEY GOES ────────────────────────────────────

            /*
             * Named payout_* rather than payment_* because companies.payment_*
             * means the opposite direction — the account a tenant pays US
             * from. Two "bank account" columns on two tables pointing opposite
             * ways is how a transfer ends up in the wrong place.
             */
            $table->string('payout_bank_name')->nullable();
            $table->string('payout_bank_account_number')->nullable();
            $table->string('payout_bank_account_name')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The listing query, and the product picker's: active suppliers by
            // name.
            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
