<?php

namespace Tests\Feature\Registration;

use App\Models\Company;
use App\Models\CompanyInviteCode;
use App\Models\TrackedLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-233 — the company signup link.
 *
 * ── WHY A WHOLE TEST FILE FOR "ADD CRUD" ──
 *
 * This is not CRUD over an existing feature. `company_invite_codes` has
 * been in the schema since ADR-005 and the application has only ever READ
 * it: no route, no controller, no service, no policy, no screen. The only
 * way a code has ever existed outside a test factory is an INSERT typed by
 * hand against production. So every rule below is being stated for the
 * first time, and none of it is protected by prior art.
 *
 * The three that matter most:
 *
 * 1. AN AGENT CANNOT MINT ONE. A company-wide link attributes its recruits
 *    to nobody. Handing it to an agent would let any agent walk straight
 *    past the `is_team_leader` gate that ADR-025 put on downline
 *    recruiting, by recruiting through the company's front door instead.
 *
 * 2. A COMPANY ADMIN CANNOT REACH ANOTHER COMPANY'S. This model is
 *    deliberately NOT TenantScope'd — it was Super-Admin-only when it was
 *    written, so nothing is standing behind the policy here. The moment a
 *    Company Admin can reach the model at all, that check is the only
 *    thing between them and a rival tenant's recruitment.
 *
 * 3. THE CODE CANNOT BE EDITED. It is the printed part of the URL.
 *    Changing it does not edit the flyer already on the wall — it kills it.
 */
class CompanyInviteCodeTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        // expires_at and max_uses are `present`, not `sometimes`: null is a
        // legal answer but the caller has to give one (BR-7).
        return array_merge([
            'code' => 'thailife',
            'label' => 'ใบปลิวสาขาสีลม',
            'expires_at' => null,
            'max_uses' => null,
        ], $overrides);
    }

    public function test_a_company_admin_creates_a_signup_link_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/company-invite-codes', $this->payload())
            ->assertCreated();

        $this->assertSame('thailife', $response->json('data.code'));
        $this->assertStringContainsString('/c/thailife', $response->json('data.signup_url'));
        $this->assertSame($company->id, $response->json('data.company_id'));

        // The short link is minted in the same call — a code that exists
        // without the link it was created to be would be half a feature.
        $this->assertSame(1, TrackedLink::withoutGlobalScopes()->where('code', 'thailife')->count());
    }

    public function test_an_agent_cannot_mint_a_company_wide_signup_link(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/company-invite-codes', $this->payload())
            ->assertForbidden();
    }

    public function test_a_company_admin_cannot_see_or_touch_another_companys_link(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $mine->id]);
        $foreign = CompanyInviteCode::factory()->create(['company_id' => $theirs->id]);

        $this->actingAs($admin)->getJson('/api/v1/company-invite-codes')
            ->assertOk()
            ->assertJsonMissing(['id' => $foreign->id]);

        $this->actingAs($admin)
            ->deleteJson('/api/v1/company-invite-codes/'.$foreign->id)
            ->assertForbidden();
    }

    public function test_a_company_admins_supplied_company_id_is_refused(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $mine->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/company-invite-codes', $this->payload(['company_id' => $theirs->id]))
            ->assertJsonValidationErrors('company_id');
    }

    public function test_the_code_must_be_url_safe(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $company = Company::factory()->create();

        foreach (['Thai Life', 'ไทยประกัน', 'thai_life', '-thailife', 'ab'] as $bad) {
            $this->actingAs($admin)
                ->postJson('/api/v1/company-invite-codes', $this->payload([
                    'code' => $bad,
                    'company_id' => $company->id,
                ]))
                ->assertJsonValidationErrors('code');
        }
    }

    public function test_a_mixed_case_code_becomes_one_link_not_two(): void
    {
        // URLs match case-sensitively, so "ThaiLife" and "thailife" would
        // otherwise be two different links that look identical in print.
        $admin = User::factory()->superAdmin()->create();
        $company = Company::factory()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/company-invite-codes', $this->payload([
                'code' => 'ThaiLife',
                'company_id' => $company->id,
            ]))
            ->assertJsonValidationErrors('code');
    }

    public function test_the_code_cannot_be_changed_after_it_has_been_printed(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $code = CompanyInviteCode::factory()->create(['company_id' => $company->id, 'code' => 'thailife']);

        $this->actingAs($admin)
            ->putJson('/api/v1/company-invite-codes/'.$code->id, ['code' => 'newcode'])
            ->assertJsonValidationErrors('code');

        $this->assertSame('thailife', $code->fresh()->code);
    }

    public function test_the_label_and_limits_can_be_changed(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $code = CompanyInviteCode::factory()->create([
            'company_id' => $company->id,
            'label' => 'เดิม',
            'max_uses' => null,
        ]);

        $this->actingAs($admin)
            ->putJson('/api/v1/company-invite-codes/'.$code->id, ['label' => 'ใหม่', 'max_uses' => 50])
            ->assertOk()
            ->assertJsonPath('data.label', 'ใหม่')
            ->assertJsonPath('data.max_uses', 50);
    }

    public function test_deleting_revokes_and_keeps_the_row(): void
    {
        // A hard delete would orphan users.registered_via_invite_code_id on
        // agents who are still working here, erasing where they came from.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $code = CompanyInviteCode::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->deleteJson('/api/v1/company-invite-codes/'.$code->id)
            ->assertOk()
            ->assertJsonPath('data.is_valid', false);

        $this->assertDatabaseHas('company_invite_codes', ['id' => $code->id]);
        $this->assertNotNull($code->fresh()->revoked_at);
    }

    public function test_revoking_the_code_also_kills_its_short_link(): void
    {
        // Otherwise /c/thailife keeps resolving after the admin believes
        // they have switched the campaign off.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $created = $this->actingAs($admin)
            ->postJson('/api/v1/company-invite-codes', $this->payload())
            ->json('data');

        $this->actingAs($admin)->deleteJson('/api/v1/company-invite-codes/'.$created['id'])->assertOk();

        $link = TrackedLink::withoutGlobalScopes()->where('code', 'thailife')->first();
        $this->assertNotNull($link->revoked_at);
        $this->assertFalse($link->isUsable());
    }

    public function test_the_public_resolver_returns_the_company_name_and_nothing_else(): void
    {
        // Unauthenticated. It must not become a window onto how big a
        // company's recruitment drive is or how much room is left in it.
        $company = Company::factory()->create(['name' => 'ไทยประกันชีวิต']);
        $code = CompanyInviteCode::factory()->create([
            'company_id' => $company->id,
            'code' => 'thailife',
            'max_uses' => 50,
            'used_count' => 7,
        ]);

        $response = $this->postJson('/api/v1/register/resolve-invite-code', ['invite_code' => 'thailife'])
            ->assertOk();

        // `theme` joined this payload on 2026-08-21 so a phone that has just
        // scanned the QR code can paint the company's own colours: a short
        // link carries no slug and a freshly-scanned device has nothing
        // cached, so this response is the only place the theme can come from.
        // SignupLinkThemeTest owns that behaviour and asserts it is
        // presentational only. This list stays CLOSED — a third key has to
        // argue with this line before it ships.
        $keys = array_keys($response->json());
        sort($keys);
        $this->assertSame(['company_name', 'theme'], $keys);
        $this->assertSame('ไทยประกันชีวิต', $response->json('company_name'));

        // The QUOTA is the thing this test was written to keep out: an
        // unauthenticated caller must not learn how big a company's
        // recruitment drive is or how much room is left in it.
        $body = $response->getContent();
        $this->assertStringNotContainsString('used_count', $body);
        $this->assertStringNotContainsString('max_uses', $body);
        $this->assertStringNotContainsString('expires_at', $body);
    }

    public function test_a_revoked_and_an_invented_code_answer_the_same_way(): void
    {
        $company = Company::factory()->create();
        CompanyInviteCode::factory()->create([
            'company_id' => $company->id,
            'code' => 'thailife',
            'revoked_at' => now(),
        ]);

        $revoked = $this->postJson('/api/v1/register/resolve-invite-code', ['invite_code' => 'thailife']);
        $invented = $this->postJson('/api/v1/register/resolve-invite-code', ['invite_code' => 'nosuchcode']);

        $revoked->assertNotFound();
        $invented->assertNotFound();
        $this->assertSame($invented->json('message'), $revoked->json('message'));
    }
}
