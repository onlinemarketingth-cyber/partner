<?php

namespace Tests\Feature\Theme;

use App\Models\AffiliateLink;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyThemeSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductShareLink;
use App\Models\Referral;
use App\Models\User;
use App\Services\Theme\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-159 §3 — the company theme must reach the three PUBLIC surfaces a
// customer outside the platform lands on. Each is resolved by an opaque
// TOKEN that already names exactly one company server-side, so the theme
// follows the token — never a hostname or a `?company=` slug (BR-6/§5
// rule 5: a client-supplied company id on an unauthenticated endpoint is
// a cross-tenant read).
//
// NOTE: this repo has no CompanyThemeSetting factory, so every theme row
// below is created with CompanyThemeSetting::create([...]) — the same
// style CertTierTargetModeTest uses where a factory is missing.
class PublicTokenThemeTest extends TestCase
{
    use RefreshDatabase;

    /** A company whose brand colour is deliberately distinct per call. */
    private function makeThemedCompany(string $primaryHex, string $fontFamily): Company
    {
        $company = Company::factory()->create();

        CompanyThemeSetting::create([
            'company_id' => $company->id,
            'primary_hex' => $primaryHex,
            'font_family' => $fontFamily,
        ]);

        return $company;
    }

    private function makeShareLink(Company $company): ProductShareLink
    {
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        return ProductShareLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
        ]);
    }

    private function makeOrder(Company $company): Order
    {
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::factory()->create(['client_id' => $client->id]);

        return Order::factory()->create(['referral_id' => $referral->id]);
    }

    private function makeAffiliateLink(Company $company): AffiliateLink
    {
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        return AffiliateLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => null,
        ]);
    }

    // ── Each token returns its OWN company's theme ─────────────────────

    public function test_two_product_share_tokens_each_return_their_own_companys_theme(): void
    {
        $companyA = $this->makeThemedCompany('#AA0000', 'Prompt');
        $companyB = $this->makeThemedCompany('#0000BB', 'Sarabun');
        $linkA = $this->makeShareLink($companyA);
        $linkB = $this->makeShareLink($companyB);

        $this->getJson("/api/v1/public/product-shares/{$linkA->token}")
            ->assertOk()
            ->assertJsonPath('data.theme.primary_hex', '#AA0000')
            ->assertJsonPath('data.theme.font_family', 'Prompt')
            ->assertJsonPath('data.theme.company.slug', $companyA->slug);

        $this->getJson("/api/v1/public/product-shares/{$linkB->token}")
            ->assertOk()
            ->assertJsonPath('data.theme.primary_hex', '#0000BB')
            ->assertJsonPath('data.theme.font_family', 'Sarabun')
            ->assertJsonPath('data.theme.company.slug', $companyB->slug);
    }

    public function test_two_pay_tokens_each_return_their_own_companys_theme(): void
    {
        $companyA = $this->makeThemedCompany('#AA0000', 'Prompt');
        $companyB = $this->makeThemedCompany('#0000BB', 'Sarabun');
        $orderA = $this->makeOrder($companyA);
        $orderB = $this->makeOrder($companyB);

        $this->getJson("/api/v1/pay/{$orderA->public_token}")
            ->assertOk()
            ->assertJsonPath('data.theme.primary_hex', '#AA0000')
            ->assertJsonPath('data.theme.company.slug', $companyA->slug);

        $this->getJson("/api/v1/pay/{$orderB->public_token}")
            ->assertOk()
            ->assertJsonPath('data.theme.primary_hex', '#0000BB')
            ->assertJsonPath('data.theme.company.slug', $companyB->slug);
    }

    public function test_two_affiliate_tokens_each_return_their_own_companys_theme(): void
    {
        $companyA = $this->makeThemedCompany('#AA0000', 'Prompt');
        $companyB = $this->makeThemedCompany('#0000BB', 'Sarabun');
        $linkA = $this->makeAffiliateLink($companyA);
        $linkB = $this->makeAffiliateLink($companyB);

        $this->getJson("/api/v1/public/affiliate-leads/{$linkA->token}")
            ->assertOk()
            ->assertJsonPath('data.theme.primary_hex', '#AA0000')
            ->assertJsonPath('data.theme.company.slug', $companyA->slug);

        $this->getJson("/api/v1/public/affiliate-leads/{$linkB->token}")
            ->assertOk()
            ->assertJsonPath('data.theme.primary_hex', '#0000BB')
            ->assertJsonPath('data.theme.company.slug', $companyB->slug);
    }

    // ── Same shape as GET /public/theme/{slug} ─────────────────────────

    public function test_the_embedded_theme_is_identical_in_shape_to_the_public_theme_endpoint(): void
    {
        $company = $this->makeThemedCompany('#123456', 'Prompt');
        $link = $this->makeShareLink($company);

        $bySlug = $this->getJson("/api/v1/public/theme/{$company->slug}")->assertOk()->json('data');
        $byToken = $this->getJson("/api/v1/public/product-shares/{$link->token}")->assertOk()->json('data.theme');

        $this->assertSame($bySlug, $byToken);
    }

    // ── No theme row → defaults, never null ────────────────────────────

    public function test_a_company_with_no_theme_row_returns_defaults_rather_than_null(): void
    {
        $defaults = app(ThemeService::class)->defaults();
        // Deliberately NOT makeThemedCompany() — no company_theme_settings row at all.
        $company = Company::factory()->create();
        $link = $this->makeShareLink($company);
        $order = $this->makeOrder($company);
        $affiliate = $this->makeAffiliateLink($company);

        $this->assertDatabaseMissing('company_theme_settings', ['company_id' => $company->id]);

        foreach ([
            "/api/v1/public/product-shares/{$link->token}",
            "/api/v1/pay/{$order->public_token}",
            "/api/v1/public/affiliate-leads/{$affiliate->token}",
        ] as $url) {
            $response = $this->getJson($url)->assertOk();

            $this->assertNotNull($response->json('data.theme'), "theme was null for {$url}");
            $response->assertJsonPath('data.theme.primary_hex', $defaults['primary_hex'])
                ->assertJsonPath('data.theme.accent_hex', $defaults['accent_hex'])
                ->assertJsonPath('data.theme.font_family', $defaults['font_family'])
                ->assertJsonPath('data.theme.background.type', $defaults['background_type'])
                ->assertJsonPath('data.theme.company.slug', $company->slug);
        }
    }

    // ── Unusable token → 404 BEFORE any theme is emitted ───────────────

    public function test_a_revoked_share_token_404s_and_emits_no_theme(): void
    {
        $company = $this->makeThemedCompany('#AA0000', 'Prompt');
        $link = $this->makeShareLink($company);
        $link->update(['revoked_at' => now()]);

        $response = $this->getJson("/api/v1/public/product-shares/{$link->token}")->assertNotFound();

        $this->assertStringNotContainsString('#AA0000', $response->getContent());
        $this->assertNull($response->json('data.theme'));
    }

    // TASK-155/156 put an inactive-product check inside
    // resolveUsableLink(); TASK-159 must sit AFTER it, so a link to a
    // withdrawn product still 404s and still leaks no branding.
    public function test_a_share_token_for_a_deactivated_product_404s_and_emits_no_theme(): void
    {
        $company = $this->makeThemedCompany('#AA0000', 'Prompt');
        $link = $this->makeShareLink($company);
        Product::withoutGlobalScopes()->whereKey($link->product_id)->update(['is_active' => false]);

        $response = $this->getJson("/api/v1/public/product-shares/{$link->token}")->assertNotFound();

        $this->assertStringNotContainsString('#AA0000', $response->getContent());
    }

    public function test_an_unknown_token_404s_on_all_three_public_surfaces_and_emits_no_theme(): void
    {
        foreach ([
            '/api/v1/public/product-shares/not-a-real-token',
            '/api/v1/pay/not-a-real-token',
            '/api/v1/public/affiliate-leads/not-a-real-token',
        ] as $url) {
            $response = $this->getJson($url)->assertNotFound();
            $this->assertNull($response->json('data.theme'), "theme leaked from {$url}");
        }
    }

    // ── BR-6 — the theme follows the TOKEN, not the request ────────────

    public function test_a_company_query_parameter_cannot_swap_the_theme_of_another_tenant(): void
    {
        $owner = $this->makeThemedCompany('#AA0000', 'Prompt');
        $other = $this->makeThemedCompany('#0000BB', 'Sarabun');
        $link = $this->makeShareLink($owner);

        // §5 rule 5 — a client-supplied company hint on a public endpoint
        // must be inert. The token is the only tenant authority here.
        $this->getJson("/api/v1/public/product-shares/{$link->token}?company={$other->slug}&company_id={$other->id}")
            ->assertOk()
            ->assertJsonPath('data.theme.primary_hex', '#AA0000')
            ->assertJsonPath('data.theme.company.slug', $owner->slug);
    }

    // ── §6 — nothing sensitive rides along ─────────────────────────────

    public function test_the_embedded_theme_exposes_no_credentials_or_internal_ids(): void
    {
        $company = $this->makeThemedCompany('#AA0000', 'Prompt');
        $link = $this->makeShareLink($company);

        $theme = $this->getJson("/api/v1/public/product-shares/{$link->token}")->assertOk()->json('data.theme');

        // ThemeResource is presentational: company name/slug, colours,
        // background, fonts, logo URLs, loading config, label + nav-icon
        // overrides, the login link and the storefront slot count. Asserted
        // as an EXACT set (sorted, so key order is not the subject) — a new
        // field added to ThemeResource must fail here and be reviewed
        // against §6 before it can reach an anonymous customer.
        $expected = [
            'accent_hex', 'background', 'card_bg_hex', 'card_border_hex', 'card_shadow',
            'card_text_hex', 'company', 'font_family', 'font_family_latin', 'font_family_thai',
            'font_weights', 'label_overrides', 'loading', 'login_link',
            // TASK-235 — `login_short_link` joined the payload. REVIEWED
            // AGAINST §6, which is what this test exists to force.
            //
            // It is the same fact as `login_link` beside it, in a shorter
            // form: both are a URL to this company's own login page, which
            // an anonymous visitor is by definition allowed to reach — that
            // is what a login page is. It carries no counts, no agent, no
            // company internals; the short code is opaque and resolves to
            // exactly the page the long one already pointed at.
            //
            // Null unless somebody has minted one, so the anonymous payload
            // is unchanged for every company that has not.
            'login_short_link', 'logos',
            // TASK-161 §3.1 — nav_bg_type / nav_bg_config joined the
            // payload. Reviewed against §6: both are presentational nav-bar
            // colour config (a type discriminator and two hex stops plus an
            // angle), the same class of value as nav_bg_hex beside them —
            // no id, no credential, nothing PDPA.
            'nav_active_hex', 'nav_bg_config', 'nav_bg_hex', 'nav_bg_type',
            'nav_icon_overrides', 'nav_text_hex',
            'primary_hex', 'recommended_slot_count',
        ];
        $actual = array_keys($theme);
        sort($actual);
        $this->assertSame($expected, $actual);

        $companyKeys = array_keys($theme['company']);
        sort($companyKeys);
        $this->assertSame(['name', 'slug'], $companyKeys);
        $this->assertArrayNotHasKey('id', $theme['company']);
        $this->assertArrayNotHasKey('company_id', $theme);
    }
}
