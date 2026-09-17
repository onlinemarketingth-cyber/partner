<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-16 — WHO BROUGHT THIS PRODUCT IN, WHICH IS NOT WHO SELLS IT.
 *
 * ── THE GAP THIS FILLS ──
 *
 * Until now a product had exactly two possible shapes, and one column said
 * which:
 *
 *   company_id = 5     one company's own product, sellable only by them
 *   company_id = NULL  a platform product, sellable by any company that
 *                      switches it on (TASK-253 / ADR-040)
 *
 * A supplier's product is a third thing that neither expresses. Put the
 * supplier in `company_id` and no other company can sell it — the entire
 * point of the arrangement. Leave it NULL and it sells fine, but nothing
 * anywhere records who supplied it, which is the fact that decides who gets
 * paid and who may look at the order.
 *
 * So a supplier product is a PLATFORM product (company_id NULL, so every
 * ADR-040 mechanism — CompanyProductSetting, per-company pricing,
 * isSellableBy() — keeps working untouched) that additionally names its
 * supplier here. "Who may sell it" and "whose product it is" stop sharing
 * one column.
 *
 * ── THE TWO OVERRIDES ──
 *
 * gp and wht default to the supplier's deal terms (companies.supplier_*) and
 * are overridden here per product, because both genuinely vary within one
 * deal: a supplier may take a different margin on a flagship item, and — more
 * importantly — withholding tax depends on whether the thing being paid for
 * is GOODS or a SERVICE, which is a property of the product and not of the
 * company. NULL here means "use the deal", never "zero".
 *
 * ── NO FOREIGN KEY CASCADE ──
 *
 * nullOnDelete, not cascade: deleting a supplier company must not delete the
 * products our companies are currently selling, nor the sales history hanging
 * off them. It orphans the attribution, which is visible and fixable; a
 * cascade would take the catalogue with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('supplier_company_id')
                ->nullable()
                ->after('company_id')
                ->constrained('companies')
                ->nullOnDelete();

            // Per-product override of companies.supplier_gp_mode / _value.
            $table->string('supplier_gp_mode', 32)->nullable()->after('cost_satang');
            $table->unsignedBigInteger('supplier_gp_value')->nullable()->after('supplier_gp_mode');

            // Per-product override of companies.supplier_wht_rate — goods and
            // services are withheld differently and both live in this table.
            $table->unsignedInteger('supplier_wht_rate')->nullable()->after('supplier_gp_value');

            // Every supplier-facing query filters on this and nothing else
            // (§5.2 of the spec): "orders whose product's supplier is me".
            $table->index('supplier_company_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['supplier_company_id']);
            $table->dropConstrainedForeignId('supplier_company_id');
            $table->dropColumn(['supplier_gp_mode', 'supplier_gp_value', 'supplier_wht_rate']);
        });
    }
};
