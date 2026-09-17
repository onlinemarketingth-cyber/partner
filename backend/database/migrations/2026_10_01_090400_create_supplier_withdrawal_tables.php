<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-16 — PAYING A SUPPLIER WHAT THEY ARE OWED.
 *
 * Owner: "ระบบ UI คล้ายกับเบิกค่าคอม แต่อันนี้ทำในมุม supplier_company".
 *
 * Deliberately the same SHAPE as commission_withdrawal_requests /
 * _items — a request, an allocation across ledger rows, a bank snapshot, a
 * transfer reference, the same five states — because the process is the same
 * process: somebody decides to pay, somebody reviews, accounting moves money.
 * Every hard-won property of that flow (allocation rather than a flag on the
 * ledger, the reserved-balance rule, settling only at transfer) applies here
 * unchanged, and re-deriving any of it would be a second chance to get it
 * wrong.
 *
 * Two things differ, and both are the reason this is its own table.
 *
 * ── 1. THE PAYEE IS A COMPANY, NOT A PERSON ──
 *
 * `supplier_company_id` where the other table has `agent_id`. That is not a
 * column rename: the bank details come from `companies.payment_bank_*`, the
 * minimum comes from `companies.supplier_min_withdrawal_satang`, and no
 * commission rule, certification tier or upline has anything to say about it.
 *
 * ── 2. TAX IS WITHHELD ──
 *
 * Owner: "ทำตามมาตรฐาน". Paying a juristic person withholds tax at source and
 * issues a certificate; paying commission to an individual, as this system has
 * always done, does not go through any of that — there is not one line about
 * tax anywhere else in this codebase.
 *
 * So the request carries FOUR figures where the commission one carries a
 * single `amount_satang`:
 *
 *   gross_satang   what the ledger rows add up to
 *   wht_satang     what is withheld
 *   net_satang     what accounting actually transfers
 *
 * Accounting pays `net`, reconciles against `gross`, and issues a certificate
 * for `wht`. Storing only one of the three and computing the others on the fly
 * would mean a rate change silently rewriting what was withheld last quarter.
 *
 * `wht_rate_at_time` is the rate for the SIMPLE case and is nullable, because
 * a request spanning goods (withheld at nothing) and services (withheld at a
 * rate) has no single rate. When rates differ the column is null and
 * `wht_satang` is the sum of per-rate groups — never an average applied to the
 * total, which would be wrong for every mixed request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_withdrawal_requests', function (Blueprint $table) {
            $table->id();

            // Who we are paying. No `company_id` twin of the commission
            // table's: a supplier is not one of our tenants, and the sales
            // being settled may span several of them.
            $table->foreignId('supplier_company_id')->constrained('companies')->restrictOnDelete();

            // App\Enums\WithdrawalSource — the SAME enum the commission flow
            // uses. A supplier asking begins at pending_review; an admin
            // raising a payout begins at approved, because the act of raising
            // it is the decision.
            $table->string('source')->default('company_payout');

            // App\Enums\WithdrawalStatus — shared with the commission flow.
            $table->string('status')->default('approved');

            // BR-3 — satang, integers, all three.
            $table->unsignedBigInteger('gross_satang');
            // Basis points. NULL means "more than one rate applied" — see the
            // class note; it is not "no tax", which is 0.
            $table->unsignedInteger('wht_rate_at_time')->nullable();
            $table->unsignedBigInteger('wht_satang')->default(0);
            // What leaves the bank account. gross − wht, always, every row.
            $table->unsignedBigInteger('net_satang');

            // Filled in by accounting, or by whatever produces the paperwork.
            // Nullable because the certificate is issued after the transfer.
            $table->string('wht_certificate_no')->nullable();

            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('transferred_at')->nullable();
            $table->string('transfer_reference')->nullable();

            /*
             * Bank details SNAPSHOTTED at request time, exactly as the
             * commission table does it. A supplier who changes account after
             * being paid must not rewrite the record of where the money went —
             * that record is the only answer to "which account did we send it
             * to" when a transfer is disputed.
             */
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_account_holder_name')->nullable();

            $table->timestamps();

            $table->index(['supplier_company_id', 'status'], 'swr_supplier_status_idx');
        });

        Schema::create('supplier_withdrawal_items', function (Blueprint $table) {
            $table->id();

            /*
             * The allocation, kept here rather than as a flag on the ledger
             * row, for the reason the commission version's migration gives: a
             * ledger row can be drawn on across more than one request, and a
             * boolean cannot say how much of it this one took.
             *
             * NO company_id: reachable only through the request, which is
             * scoped. A second copy of the tenant key is a second place for it
             * to be wrong.
             */
            $table->foreignId('supplier_withdrawal_request_id')
                ->constrained('supplier_withdrawal_requests')
                ->cascadeOnDelete();

            $table->foreignId('supplier_settlement_ledger_id')
                ->constrained('supplier_settlement_ledger')
                ->restrictOnDelete();

            // SIGNED, unlike the commission twin. A negative settlement row —
            // where commission plus GP exceeded the sale price and the
            // supplier carries the difference — is allocated into the payout
            // alongside the positive ones so it nets off and is CLOSED. Left
            // out, it would sit in the balance forever, quietly reducing every
            // future payout by the same amount, over and over.
            $table->bigInteger('allocated_satang');

            $table->timestamps();

            $table->unique(
                ['supplier_withdrawal_request_id', 'supplier_settlement_ledger_id'],
                'swi_request_ledger_unique',
            );
            $table->index('supplier_settlement_ledger_id', 'swi_ledger_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_withdrawal_items');
        Schema::dropIfExists('supplier_withdrawal_requests');
    }
};
