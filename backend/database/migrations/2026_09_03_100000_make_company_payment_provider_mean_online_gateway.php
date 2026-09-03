<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * companies.payment_provider stops meaning "the one channel" and starts
 * meaning "the ONLINE gateway, if any".
 *
 * ── WHY THE MEANING CHANGES ──
 *
 * 2026-09-03, human decision. Bank transfer / PromptPay is not one option
 * among three — it is always available, in every company, and it always was.
 * A customer picks ONE method when they pay; the company decides which CARD
 * gateway sits beside the transfer option, or none.
 *
 * Keeping 'manual' in this column forced the opposite model: turning Stripe
 * on turned bank transfer OFF, which no Thai seller wants and which nobody
 * had actually asked for. It was an accident of storing three things in a
 * column that only ever needed to hold the answer to one question.
 *
 * ── WHY 'manual' BECOMES NULL AND NOT A NEW COLUMN ──
 *
 * A second column would leave two places that both claim to say how a
 * company takes money, and the stale one would eventually win an argument.
 * NULL is the honest value for "no online gateway", it is what every company
 * currently on 'manual' actually means, and the conversion is lossless: the
 * transfer flow they are using does not live in this column any more.
 *
 * ORDERS ARE NOT TOUCHED. orders.payment_provider keeps 'manual' on every
 * order taken that way — it records how a specific sale was collected, which
 * is history and must not be rewritten. Only the COMPANY's forward-looking
 * setting changes meaning here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // NULL is now a real, expected value, so the column must accept
            // it and must not fall back to a default that means something.
            $table->string('payment_provider')->nullable()->default(null)->change();
        });

        DB::table('companies')->where('payment_provider', 'manual')->update(['payment_provider' => null]);
    }

    public function down(): void
    {
        // Back to the old model: no online gateway meant the manual flow was
        // THE provider, so that is what a rollback has to restore.
        DB::table('companies')->whereNull('payment_provider')->update(['payment_provider' => 'manual']);

        Schema::table('companies', function (Blueprint $table) {
            $table->string('payment_provider')->default('manual')->nullable(false)->change();
        });
    }
};
