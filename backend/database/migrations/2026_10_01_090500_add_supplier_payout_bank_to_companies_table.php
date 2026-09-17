<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-17 — THE ACCOUNT WE PAY A SUPPLIER INTO, WHICH IS NOT THE ACCOUNT
 * THEY TAKE CUSTOMERS' MONEY IN.
 *
 * The supplier payout work shipped reading `companies.payment_bank_*` for the
 * transfer details. Those columns already mean something else: they are the
 * account a company RECEIVES customer payments into, set on the "ตั้งค่าการ
 * ชำระเงิน" screen and printed on the customer-facing pay page.
 *
 * Reusing them worked, and was wrong in two ways the owner and I talked
 * through before choosing this:
 *
 *   1. IN PRACTICE THEY ARE DIFFERENT ACCOUNTS. Money coming in from the
 *      public and money coming in from a trading partner are routinely
 *      reconciled separately, by different people.
 *   2. IT WOULD HAVE MEANT A WIDER PERMISSION. Somebody has to be able to set
 *      a supplier's payout account, and the only person positioned to is a
 *      Super Admin — so reusing the column would have handed Super Admins an
 *      edit on the account another tenant collects CUSTOMER money into. A new
 *      column keeps that edit to the thing it is for.
 *
 * All three nullable with no default (BR-7): a supplier with no account on
 * file is not payable, and the payout screen says so rather than guessing.
 * SupplierPayoutService still snapshots whatever is here onto each request, so
 * changing the account later never rewrites where past money went.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('supplier_payout_bank_name')->nullable()->after('supplier_wht_rate');
            $table->string('supplier_payout_bank_account_number')->nullable()->after('supplier_payout_bank_name');
            $table->string('supplier_payout_bank_account_name')->nullable()->after('supplier_payout_bank_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'supplier_payout_bank_name',
                'supplier_payout_bank_account_number',
                'supplier_payout_bank_account_name',
            ]);
        });
    }
};
