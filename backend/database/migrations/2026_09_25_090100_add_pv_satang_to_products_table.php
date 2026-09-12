<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2026-09-12 (owner): the PV figure itself.
 *
 * ── NULLABLE, AND IT MUST STAY NULLABLE ──
 *
 * NULL means "this product has no PV yet", which is a different fact from
 * "this product is worth 0 PV" — and the difference decides whether an
 * agent is paid. A default of 0 would have made every existing product
 * silently worth nothing the moment a company switched its basis to PV:
 * every rate resolves, every gate passes, every ledger row is written for
 * 0 satang, and the first anybody hears of it is a payout. So:
 *
 *   NULL  → CommissionBasisResolver falls back to the sale price and
 *           CommissionReadinessService raises it as an unfinished setup
 *           (amber) on every admin page until a Super Admin fills it in.
 *   0     → a deliberate "this product pays no commission", honoured.
 *
 * The fallback is deliberately NOT a refusal to pay. A missing PV is a
 * configuration gap, and this system's standing rule for a configuration
 * gap is that it must never quietly turn into a smaller number in an
 * immutable ledger row (BR-3/BR-4) — paying the price basis pays what the
 * company was being paid the day before it flipped the switch, which is
 * the one answer nobody can be surprised by. The banner is what makes the
 * gap loud.
 *
 * ── WHY ON products AND NOT ON company_product_settings ──
 *
 * ADR-040's settings table says, in its own migration comment, that
 * commission deliberately does not live there: per-company commission
 * variation already has a home in `commission_rules` (product_id +
 * company_id, BR-2), and a second place to look is a second place to
 * disagree. PV is the product's commissionable VALUE — the same figure
 * for everyone who sells it, exactly like the central price — and a
 * company that wants to pay differently on it changes its rate, not the
 * product's worth.
 *
 * ── SATANG, NOT POINTS ──
 *
 * See App\Enums\CommissionBasis for why PV rides the same integer satang
 * scale as price. Named pv_satang rather than pv_value so the unit is
 * impossible to misread at a call site (BR-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('pv_satang')
                ->nullable()
                ->after('price_satang');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('pv_satang');
        });
    }
};
