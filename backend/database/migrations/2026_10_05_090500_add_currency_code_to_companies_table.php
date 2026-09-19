<?php

use App\Support\Money\SupportedCurrency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What currency a tenant's money is in.
 *
 * ═══ WHAT WAS WRONG ═══
 *
 * Every amount in this system is an integer number of satang and every
 * screen prints ฿ in front of it. That is correct for a Thai tenant and
 * wrong for any other, and the platform is multi-tenant by design — a
 * company in Singapore would have had its prices, commission, payouts and
 * withdrawal floors all rendered as baht, with nothing anywhere recording
 * that they were not.
 *
 * ═══ WHAT THIS IS NOT ═══
 *
 * It is NOT multi-currency, and it introduces no exchange rate. Amounts are
 * never converted; a company's money stays in that company's currency, and
 * this column says which one so it can be labelled honestly. An FX rate is
 * a business value with a daily-changing number and an accountable source
 * (BR-7), and nothing here is entitled to invent either.
 *
 * Two consequences a reader should know about, both deliberate:
 *
 *   · Only hundredth-based currencies are accepted, because BR-3 stores
 *     satang and the whole codebase divides by 100 at the display layer.
 *     App\Support\Money\SupportedCurrency states this at length and holds
 *     the list.
 *   · Any figure that SUMS ACROSS COMPANIES is now suspect. The platform
 *     reports do exactly that today — see the TODO on PlatformReportService.
 *     Adding this column does not create that problem, it makes it visible;
 *     the fix is a decision about what a cross-currency total should even
 *     mean, which is the owner's.
 *
 * ═══ THE DEFAULT IS TODAY'S BEHAVIOUR ═══
 *
 * 'THB', NOT NULL, applied to every existing row. Every company on this
 * system is a Thai company storing satang, so the default states what is
 * already true rather than guessing. Same safety argument as
 * commission_basis, override_compression and volume_scope before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // ISO 4217 alpha-3. char(3), not string(255): the value has a
            // fixed width and a closed vocabulary, and a column that can hold
            // a sentence invites one.
            $table->char('currency_code', 3)
                ->default(SupportedCurrency::DEFAULT)
                ->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });
    }
};
