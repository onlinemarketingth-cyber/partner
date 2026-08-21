<?php

namespace Tests\Feature\Registration;

use App\Models\AgentInviteLink;
use App\Models\Company;
use App\Models\CompanyInviteCode;
use App\Models\CompanyThemeSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two signup links carry the inviting company's THEME.
 *
 * ── THE BUG, REPORTED 2026-08-21 ──
 *
 * "เข้าที่มือถือ หรือ scan qr code … สีของ theme ไม่มา."
 *
 * The theme store resolves a company pre-login from `?company=<slug>` or,
 * failing that, from a slug cached in localStorage. A short signup link has
 * neither: /j/<code> and /c/<code> carry no slug — that is what makes them
 * short — and a device that has never signed in has nothing cached. So the
 * recruit saw the neutral platform brand instead of the company that invited
 * them.
 *
 * ── WHY IT SURVIVED TESTING ──
 *
 * On the desktop the links were tested from, localStorage already held a
 * slug from an earlier login, so the page themed correctly and hid the bug
 * completely. A phone that has just scanned the QR code has nothing cached —
 * and an in-app browser (LINE, the camera app, Facebook) often starts with
 * empty storage on every open, so it never accumulates one. The one audience
 * a QR code exists for was the one audience that never saw the branding.
 *
 * That is why these assertions are about the PAYLOAD and not about pixels:
 * the only thing the server can do here is put the theme in the response,
 * and if it stops doing so the page silently falls back to neutral. Nothing
 * throws, nothing 500s, and every other test in this suite still passes.
 */
class SignupLinkThemeTest extends TestCase
{
    use RefreshDatabase;

    private function themedCompany(string $primary = '#B08D57'): Company
    {
        $company = Company::factory()->create(['slug' => 'thailife']);
        CompanyThemeSetting::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'primary_hex' => $primary,
        ]);

        return $company;
    }

    public function test_the_company_signup_link_carries_the_theme(): void
    {
        $company = $this->themedCompany();
        $code = CompanyInviteCode::factory()->create(['company_id' => $company->id]);

        $this->postJson('/api/v1/register/resolve-invite-code', ['invite_code' => $code->code])
            ->assertOk()
            ->assertJsonPath('theme.primary_hex', '#B08D57')
            ->assertJsonPath('theme.company.slug', 'thailife');
    }

    public function test_the_team_signup_link_carries_the_theme(): void
    {
        $company = $this->themedCompany();
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $link = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
        ]);

        $this->postJson('/api/v1/register/resolve-ref-token', ['ref_token' => $link->token])
            ->assertOk()
            ->assertJsonPath('theme.primary_hex', '#B08D57');
    }

    public function test_a_company_with_no_theme_row_still_gets_a_full_payload(): void
    {
        // ThemeService::forCompanyPublic() hydrates an unsaved model so the
        // Resource emits defaults(). A null here would make the SPA's
        // applyResolved() a no-op, which is correct behaviour but for the
        // wrong reason — and it would mean this endpoint could be used to
        // probe which companies have configured a theme.
        $company = Company::factory()->create(['slug' => 'plain']);
        $code = CompanyInviteCode::factory()->create(['company_id' => $company->id]);

        $this->postJson('/api/v1/register/resolve-invite-code', ['invite_code' => $code->code])
            ->assertOk()
            ->assertJsonPath('theme.company.slug', 'plain')
            ->assertJsonStructure(['theme' => ['primary_hex', 'nav_bg_hex', 'logos', 'loading']]);
    }

    public function test_a_dead_link_emits_no_theme_at_all(): void
    {
        // The theme is only reachable AFTER the abort_unless() that rejects
        // an unknown, expired or revoked credential. If that order ever
        // inverted, a revoked link would still hand out a company's full
        // branding — logo URLs, colours, login copy — to anyone holding a
        // dead token, which is the leak TASK-183 §3.5 closed on the slug
        // endpoint and must not reopen here.
        $company = $this->themedCompany();
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $link = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
            'revoked_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/register/resolve-ref-token', ['ref_token' => $link->token]);

        $response->assertNotFound();
        $this->assertStringNotContainsString('B08D57', $response->getContent());
    }

    public function test_the_theme_is_presentational_only(): void
    {
        // It rides on an UNAUTHENTICATED endpoint. ThemeResource is the same
        // serialiser /public/theme/{slug} already exposes to anyone with a
        // slug, so nothing new is published — but a future field added to
        // that Resource would be published here too, and this is the test
        // that notices.
        $company = $this->themedCompany();
        $code = CompanyInviteCode::factory()->create(['company_id' => $company->id]);

        $body = $this->postJson('/api/v1/register/resolve-invite-code', ['invite_code' => $code->code])
            ->assertOk()
            ->json('theme');

        foreach (['id', 'company_id', 'agent_count', 'created_at'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $body);
        }
    }
}
