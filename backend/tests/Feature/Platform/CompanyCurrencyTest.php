<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\User;
use App\Support\Money\SupportedCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What currency a tenant's money is in.
 *
 * ═══ WHAT WAS WRONG ═══
 *
 * Every amount is an integer number of satang and every screen prints ฿ in
 * front of it. Correct for a Thai tenant, wrong for any other — and the
 * platform is multi-tenant by design, so a company outside Thailand would
 * have had its prices, commission, payouts and withdrawal floors all
 * rendered as baht with nothing anywhere recording that they were not.
 *
 * ═══ WHAT IS DELIBERATELY NOT HERE ═══
 *
 * Conversion. No exchange rate exists in this system and this change adds
 * none: a rate is a daily-changing business value with an accountable
 * source (BR-7 twice — which source, and who carries the spread). A
 * company's money stays in that company's currency and is labelled, never
 * translated.
 *
 * The restricted list is the other half of that honesty, and the test for
 * it below is the one that matters most: BR-3 stores HUNDREDTHS and every
 * formatter divides by 100, so a 0-decimal currency would silently multiply
 * every stored figure by a hundred. A picker that offered JPY would be a
 * lie, so the API refuses it.
 */
class CompanyCurrencyTest extends TestCase
{
    use RefreshDatabase;

    // ── The promise that nothing moved ──────────────────────────────────────

    public function test_a_company_created_without_a_currency_is_thai_baht(): void
    {
        // Every company that exists today. The default states what is already
        // true of their stored satang rather than guessing at it.
        $company = Company::factory()->create();

        $this->assertSame('THB', $company->fresh()->currencyCode());
    }

    public function test_a_row_whose_currency_is_unrecognised_reads_as_thai_baht(): void
    {
        /*
         * A hand-edited value, or a row written by a newer deploy that knows
         * a code this build does not. Resolving to anything else would
         * relabel money nobody re-denominated — and throwing would take down
         * every screen that renders a price.
         */
        $company = Company::factory()->create();
        Company::withoutGlobalScopes()->where('id', $company->id)->update(['currency_code' => 'XXX']);

        $this->assertSame('THB', $company->fresh()->currencyCode());
    }

    // ── The restriction that keeps BR-3 true ────────────────────────────────

    public function test_a_currency_that_is_not_based_on_hundredths_is_refused(): void
    {
        /*
         * THE TEST THIS WHOLE AREA EXISTS FOR.
         *
         * JPY has no minor unit. Accepting it would make every amount in that
         * tenant a hundred times the real one — silently, because nothing
         * would throw and every screen would render a plausible number. The
         * first symptom would be a payout.
         *
         * Supporting it properly means a minor-unit digit count consulted by
         * every formatter in two frontends. Until that exists, the honest
         * answer is a refusal.
         */
        $company = Company::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/companies/{$company->id}", ['currency_code' => 'JPY'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('currency_code');

        $this->assertSame('THB', $company->fresh()->currencyCode());
    }

    public function test_the_supported_list_contains_only_hundredth_based_currencies(): void
    {
        // A unit-level guard on the list itself, so adding an entry that
        // breaks BR-3 fails here rather than in a tenant's payout. These are
        // the ISO 4217 codes with an exponent other than 2 that somebody is
        // most likely to reach for.
        $notHundredths = ['JPY', 'KRW', 'VND', 'IDR', 'KWD', 'BHD', 'OMR', 'TND', 'CLP', 'ISK'];

        foreach ($notHundredths as $code) {
            $this->assertNotContains(
                $code,
                SupportedCurrency::codes(),
                "{$code} does not have two decimal places — see SupportedCurrency's docblock before adding it",
            );
        }
    }

    // ── Choosing one ────────────────────────────────────────────────────────

    public function test_a_super_admin_can_set_a_supported_currency(): void
    {
        $company = Company::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/companies/{$company->id}", ['currency_code' => 'SGD'])
            ->assertOk()
            ->assertJsonPath('data.currency_code', 'SGD')
            ->assertJsonPath('data.currency_symbol', 'S$');

        $this->assertSame('SGD', $company->fresh()->currencyCode());
    }

    public function test_a_company_admin_cannot_change_the_currency(): void
    {
        // BR-6 / §5 — the currency is a property of the tenant boundary
        // itself, and this endpoint is Super-Admin-only like the rest of it.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/companies/{$company->id}", ['currency_code' => 'USD'])
            ->assertForbidden();

        $this->assertSame('THB', $company->fresh()->currencyCode());
    }

    public function test_a_company_can_be_provisioned_in_its_own_currency(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/companies', [
                'name' => 'Live to 100 Malaysia',
                'slug' => 'live-to-100-my',
                'currency_code' => 'MYR',
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency_code', 'MYR')
            ->assertJsonPath('data.currency_symbol', 'RM');
    }

    // ── Telling the frontends ───────────────────────────────────────────────

    public function test_an_agent_is_told_their_own_companys_currency(): void
    {
        /*
         * The platform companies resource is Super-Admin-only, and the people
         * who read prices, commission and payout amounts all day are agents.
         * Without this the portal's only source for a symbol is a hardcoded
         * ฿, which is the bug this column exists to fix.
         */
        $company = Company::factory()->create(['currency_code' => 'SGD']);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.company.currency_code', 'SGD')
            ->assertJsonPath('data.company.currency_symbol', 'S$');
    }

    public function test_the_supported_currencies_are_served_from_the_api(): void
    {
        // One list, on the server. Two copies would mean the one in the
        // browser keeps a removed option on screen until somebody redeploys.
        $this->actingAs(User::factory()->agent()->create())
            ->getJson('/api/v1/currencies')
            ->assertOk()
            ->assertJsonPath('default', 'THB')
            ->assertJsonPath('data.0.code', 'THB')
            ->assertJsonPath('data.0.symbol', '฿');
    }

    public function test_an_unrecognised_code_renders_as_the_code_and_never_as_baht(): void
    {
        // A wrong symbol on a real number is worse than an unstyled one: "฿"
        // in front of a dollar figure reads as a correct baht amount.
        $this->assertSame('XYZ', SupportedCurrency::symbol('XYZ'));
        $this->assertSame('฿', SupportedCurrency::symbol('THB'));
    }
}
