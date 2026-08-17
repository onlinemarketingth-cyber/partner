<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-017 (TASK-054) — per-company payment collection config. These are
// BR-7 admin-editable values (a company's own bank account + PromptPay
// proxy id), NEVER hardcoded in logic — shown on the public /pay/{token}
// page and settable by a Company Admin/Super Admin. All nullable: a
// company that hasn't configured payment simply shows no bank/QR details.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('payment_promptpay_id')->nullable()->after('commission_plan_type');
            $table->string('payment_bank_name')->nullable()->after('payment_promptpay_id');
            $table->string('payment_bank_account_number')->nullable()->after('payment_bank_name');
            $table->string('payment_bank_account_name')->nullable()->after('payment_bank_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'payment_promptpay_id',
                'payment_bank_name',
                'payment_bank_account_number',
                'payment_bank_account_name',
            ]);
        });
    }
};
