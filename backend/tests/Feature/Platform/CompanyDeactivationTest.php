<?php

namespace Tests\Feature\Platform;

use App\Models\AffiliateLink;
use App\Models\AgentInviteLink;
use App\Models\Company;
use App\Models\CompanyInviteCode;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSalesMaterial;
use App\Models\ProductShareLink;
use App\Models\SalesMaterialShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TASK-183 — a deactivated or soft-deleted company must actually stop working.
 *
 * Before this, `companies.is_active` and `companies.deleted_at` were enforced
 * NOWHERE: outside $fillable/casts, a Resource and two Form Requests, nothing
 * read them (CompanyService's own docblock said so). An Admin who closed a
 * company saw the switch flip and reasonably concluded access had been
 * withdrawn; it had not. Every test here pins one part of closing that.
 *
 * The FIRST test is §3.3 — the one the spec calls out as most likely to be
 * missing, and the one that decides whether "deactivate" means anything at all
 * for a user who is already logged in.
 */
class CompanyDeactivationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * POST /api/v1/login the way the SPA does. Copied verbatim from
     * LoginGateTest (including pinning the stateful list rather than trusting
     * a developer's .env) so the two files cannot drift on how a login is made.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postLogin(array $payload): TestResponse
    {
        config(['sanctum.stateful' => ['agent.localhost']]);

        return $this->withHeader('Origin', 'http://agent.localhost')
            ->postJson('/api/v1/login', $payload);
    }

    /**
     * A follow-up request on the session the login above minted. The Origin
     * header is not decoration here either: Sanctum only treats a request as
     * stateful (i.e. only consults the session guard at all) when the origin
     * matches config('sanctum.stateful'). Without it this would be a bare
     * 401 and would prove nothing about company status.
     */
    private function getWithSession(string $uri): TestResponse
    {
        return $this->withHeader('Origin', 'http://agent.localhost')->getJson($uri);
    }

    private function postWithSession(string $uri): TestResponse
    {
        return $this->withHeader('Origin', 'http://agent.localhost')->postJson($uri);
    }

    // ── §3.3 — enforcement on EVERY authenticated request ─────────────────

    /**
     * THE TEST THAT MATTERS MOST (spec §3.3, §5 bullet 2).
     *
     * The session here is minted BEFORE the deactivation, through the real
     * login endpoint — not with actingAs(). That distinction is the whole
     * point: actingAs() would inject an authenticated user directly and would
     * still pass if the only enforcement lived in LoginGateService. This
     * sequence (log in successfully -> Admin deactivates -> next request)
     * is the one that fails on login-only enforcement, and it is the realistic
     * one: for a user who is active every day, "takes effect at the next
     * login" means "never".
     */
    public function test_a_session_minted_before_deactivation_is_refused_on_the_very_next_request(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->agent()->create([
            'company_id' => $company->id,
            'password' => bcrypt('password123'),
        ]);

        // 1. A perfectly normal, successful login and an authenticated read.
        $this->postLogin(['email' => $user->email, 'password' => 'password123'])->assertOk();
        $this->getWithSession('/api/v1/me')->assertOk();

        // 2. The Admin closes the company. Nothing touches the session.
        $company->update(['is_active' => false]);

        // 3. The SAME session must now be refused — with the company reason,
        //    not a 401 that would make the SPA say "session expired".
        $this->getWithSession('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'company_inactive');
    }

    /** The same guarantee for the OTHER credential Sanctum issues (mobile, ADR-003). */
    public function test_a_personal_access_token_minted_before_deactivation_stops_working(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->agent()->create(['company_id' => $company->id]);

        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me')->assertOk();

        $company->update(['is_active' => false]);

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'company_inactive');
    }

    /** §5 bullet 3 — a soft-deleted company behaves identically to is_active = false. */
    public function test_a_soft_deleted_company_refuses_a_live_session_identically(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->agent()->create([
            'company_id' => $company->id,
            'password' => bcrypt('password123'),
        ]);

        $this->postLogin(['email' => $user->email, 'password' => 'password123'])->assertOk();

        // NOTE what is NOT touched: is_active stays true. A guard that only
        // read is_active would let this whole test pass while a deleted
        // tenant kept trading.
        $company->delete();

        $this->getWithSession('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'company_inactive');
    }

    /**
     * The refusal reaches a COMPANY ADMIN, not just Agents. This is why the
     * check sits above LoginGateService's `! $user->isAgent()` early return:
     * leaving the company's own administrator able to create users, edit
     * commission config and confirm payments inside a closed tenant would be
     * the wrong half of the control.
     */
    public function test_a_company_admin_of_a_deactivated_company_is_refused_too(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $admin = User::factory()->companyAdmin()->create([
            'company_id' => $company->id,
            'password' => bcrypt('password123'),
        ]);

        $this->postLogin(['email' => $admin->email, 'password' => 'password123'])->assertOk();

        $company->update(['is_active' => false]);

        $this->getWithSession('/api/v1/me')->assertForbidden();
        // And a write, not just the read — /me is the cheapest probe, this is
        // the one that would have created a user inside a closed tenant.
        $this->withHeader('Origin', 'http://agent.localhost')
            ->postJson('/api/v1/users', [
                'first_name' => 'Somchai',
                'last_name' => 'Jaidee',
                'email' => 'somchai@thailife.test',
                'password' => 'TempPass123',
                'role' => 'agent',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'somchai@thailife.test']);
    }

    /**
     * THE ONE DELIBERATE EXCLUSION (routes/api.php). Logout grants nothing —
     * it destroys the session — and refusing it would leave the user unable to
     * end a session they can no longer use, on a UI whose only remaining
     * button is "log out". Pinned so the exclusion reads as a decision.
     */
    public function test_logout_still_works_for_a_user_of_a_deactivated_company(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->agent()->create([
            'company_id' => $company->id,
            'password' => bcrypt('password123'),
        ]);

        $this->postLogin(['email' => $user->email, 'password' => 'password123'])->assertOk();

        $company->update(['is_active' => false]);

        $this->postWithSession('/api/v1/logout')->assertNoContent();

        // The session really is gone — asserted by asking for it back rather
        // than by assertGuest(). Auth::forgetGuards() first, for the same
        // reason LoginGateTest calls it: within a single test the application
        // instance is reused, so the guard still holds the user object it
        // resolved during the request above; without this, the assertion would
        // be reading a stale in-process cache rather than the session store.
        Auth::forgetGuards();

        $this->getWithSession('/api/v1/me')->assertUnauthorized();
    }

    // ── §3.2 — enforcement at login ───────────────────────────────────────

    /**
     * §5 bullet 1 — the message names the COMPANY's status, not a credential
     * failure, so the reader is sent to their company rather than to a
     * password reset that would change nothing.
     */
    public function test_a_user_of_a_deactivated_company_cannot_log_in(): void
    {
        $company = Company::factory()->create(['is_active' => false]);
        $user = User::factory()->agent()->create([
            'company_id' => $company->id,
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postLogin(['email' => $user->email, 'password' => 'password123'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'company_inactive');

        // Names the company, says nothing about the password.
        $this->assertStringContainsString('บริษัท', $response->json('message'));

        // The gate must not leave a usable session behind (LoginRequest's
        // Auth::logout() on the blocked path).
        $this->assertGuest();
    }

    public function test_a_soft_deleted_company_blocks_login_identically(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->agent()->create([
            'company_id' => $company->id,
            'password' => bcrypt('password123'),
        ]);

        $company->delete();

        $this->postLogin(['email' => $user->email, 'password' => 'password123'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'company_inactive');

        $this->assertGuest();
    }

    /**
     * §3.4 — distinguishable from a password failure. A wrong password is a
     * 422 with the error under `errors.email`; this is a 403 with a machine
     * -readable code and no field error, so the SPA cannot render it on the
     * password box.
     */
    public function test_the_company_refusal_is_not_confused_with_a_wrong_password(): void
    {
        $company = Company::factory()->create(['is_active' => false]);
        $user = User::factory()->agent()->create([
            'company_id' => $company->id,
            'password' => bcrypt('password123'),
        ]);

        $blocked = $this->postLogin(['email' => $user->email, 'password' => 'password123']);
        $wrongPassword = $this->postLogin(['email' => $user->email, 'password' => 'nope-not-it']);

        $blocked->assertStatus(403)->assertJsonMissingPath('errors.email');
        $wrongPassword->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertNotSame($blocked->json('message'), $wrongPassword->json('message'));
    }

    /**
     * The company refusal is answered FIRST, ahead of the three registration
     * -state refusals. Nothing this person does to their email or their
     * approval status reopens a closed company, so telling them to verify or
     * to wait would be a false instruction.
     */
    public function test_the_company_refusal_is_answered_before_the_registration_state_refusals(): void
    {
        $company = Company::factory()->create(['is_active' => false]);
        $code = CompanyInviteCode::factory()->create(['company_id' => $company->id]);

        // Deliberately the worst-case row: unverified AND pending AND
        // self-registered, i.e. it would trip every other branch of the gate.
        $user = User::factory()->agent()->pendingApproval()->unverified()->create([
            'company_id' => $company->id,
            'password' => bcrypt('password123'),
            'registered_via_invite_code_id' => $code->id,
        ]);

        $this->postLogin(['email' => $user->email, 'password' => 'password123'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'company_inactive');
    }

    // ── Super Admin must never be locked out ──────────────────────────────

    /**
     * §5 bullet 4. A Super Admin has company_id = null. This is not merely a
     * convenience: the Super Admin is the ONLY role that can reactivate a
     * company, so gating them would make a deactivation irreversible through
     * the API. The reactivation half is asserted, not just the login.
     */
    public function test_a_super_admin_is_unaffected_and_can_still_reactivate_the_company(): void
    {
        $company = Company::factory()->create(['is_active' => false]);
        $superAdmin = User::factory()->superAdmin()->create(['password' => bcrypt('password123')]);

        $this->postLogin(['email' => $superAdmin->email, 'password' => 'password123'])->assertOk();
        $this->getWithSession('/api/v1/me')->assertOk();

        $this->withHeader('Origin', 'http://agent.localhost')
            ->putJson('/api/v1/companies/'.$company->id, [
                'name' => $company->name,
                'slug' => $company->slug,
                'is_active' => true,
            ])
            ->assertOk();

        $this->assertTrue($company->fresh()->isOperational());
    }

    /** An operational company is untouched — the guard must not block everybody. */
    public function test_a_user_of_an_operational_company_is_unaffected(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->agent()->create([
            'company_id' => $company->id,
            'password' => bcrypt('password123'),
        ]);

        $this->postLogin(['email' => $user->email, 'password' => 'password123'])->assertOk();
        $this->getWithSession('/api/v1/me')->assertOk();
    }

    // ── §3.5 — the PUBLIC endpoints ───────────────────────────────────────

    /** GET /api/v1/public/theme/{slug} — the pre-login branded front door. */
    public function test_public_theme_is_refused_for_a_non_operational_company(): void
    {
        $deactivated = Company::factory()->create(['is_active' => false]);
        $deleted = Company::factory()->create(['is_active' => true]);
        $live = Company::factory()->create(['is_active' => true]);

        $deleted->delete();

        $this->getJson('/api/v1/public/theme/'.$deactivated->slug)->assertNotFound();
        // The soft-delete half is worth its own assertion here specifically:
        // this controller resolves with withoutGlobalScopes(), which drops
        // SoftDeletingScope, so before TASK-183 a DELETED company still served
        // its full theme to anyone who guessed the slug.
        $this->getJson('/api/v1/public/theme/'.$deleted->slug)->assertNotFound();

        $this->getJson('/api/v1/public/theme/'.$live->slug)->assertOk();
    }

    /** GET /api/v1/pay/{token} + POST /api/v1/pay/{token}/slip — the money one. */
    public function test_public_payment_page_and_slip_upload_are_refused(): void
    {
        $order = Order::factory()->create();
        $company = Company::findOrFail($order->company_id);

        $this->getJson('/api/v1/pay/'.$order->public_token)->assertOk();

        $company->update(['is_active' => false]);

        $this->getJson('/api/v1/pay/'.$order->public_token)->assertNotFound();
        $this->postJson('/api/v1/pay/'.$order->public_token.'/slip', [
            'slip' => UploadedFile::fake()->create('slip.jpg', 64, 'image/jpeg'),
        ])->assertNotFound();
    }

    /**
     * The product-share family. All five public routes (showcase, media
     * stream, media thumbnail, material stream, checkout) go through the ONE
     * resolveUsableLink() line, so the showcase read and the checkout WRITE
     * between them exercise both sides of it; the three file routes need real
     * media rows to reach the resolver and are covered by the same guard,
     * which the mutation run confirms.
     */
    public function test_public_product_share_landing_and_checkout_are_refused(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $product = Product::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $link = ProductShareLink::factory()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'agent_id' => User::factory()->agent()->create(['company_id' => $company->id]),
        ]);

        $this->getJson('/api/v1/public/product-shares/'.$link->token)->assertOk();

        $company->update(['is_active' => false]);

        $this->getJson('/api/v1/public/product-shares/'.$link->token)->assertNotFound();

        $this->postJson('/api/v1/public/product-shares/'.$link->token.'/checkout', [
            'name' => 'ลูกค้า ทดสอบ',
            'phone' => '0812345678',
            'consent' => true,
        ])->assertNotFound();

        // The write really did not happen — a 404 that still created the row
        // would be the worst of both.
        $this->assertDatabaseCount('orders', 0);
    }

    /** GET /api/v1/l/{token} + the lead-capture read and write. */
    public function test_public_affiliate_redirect_and_lead_capture_are_refused(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $link = AffiliateLink::factory()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'agent_id' => User::factory()->agent()->create(['company_id' => $company->id]),
        ]);

        $company->update(['is_active' => false]);

        $this->get('/api/v1/l/'.$link->token)->assertNotFound();
        $this->getJson('/api/v1/public/affiliate-leads/'.$link->token)->assertNotFound();
        $this->postJson('/api/v1/public/affiliate-leads/'.$link->token, [
            'name' => 'ลูกค้า ทดสอบ',
            'phone' => '0812345678',
            'branch' => 'สาขาทดสอบ',
            'consent' => true,
        ])->assertNotFound();

        // Refused BEFORE AffiliateLinkClickService::record() — a dead link
        // must not even accrue analytics for a closed tenant.
        $this->assertDatabaseCount('affiliate_link_clicks', 0);
        $this->assertDatabaseCount('clients', 0);
    }

    /** GET /api/v1/share/sales-materials/{token} — the collateral download. */
    public function test_public_sales_material_share_download_is_refused(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $material = ProductSalesMaterial::factory()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
        ]);
        $link = SalesMaterialShareLink::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'sales_material_id' => $material->id,
            'created_by_user_id' => $agent->id,
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
            'view_count' => 0,
        ]);

        $company->update(['is_active' => false]);

        $this->get('/api/v1/share/sales-materials/'.$link->token)->assertNotFound();

        // Refused before recordView() — no view counter for a closed tenant.
        $this->assertSame(0, (int) $link->fresh()->view_count);
    }

    /** The two registration resolvers + the registration write itself. */
    public function test_registration_through_an_invite_code_is_refused_for_a_closed_company(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $code = CompanyInviteCode::factory()->create(['company_id' => $company->id]);

        $this->postJson('/api/v1/register/resolve-invite-code', ['invite_code' => $code->code])->assertOk();

        $company->update(['is_active' => false]);

        $this->postJson('/api/v1/register/resolve-invite-code', ['invite_code' => $code->code])
            ->assertNotFound();

        $this->postJson('/api/v1/register', [
            'invite_code' => $code->code,
            'first_name' => 'Somchai',
            'last_name' => 'Jaidee',
            'email' => 'somchai@thailife.test',
            'id_document_type' => 'thai_national_id',
            'national_id' => '1234567890121',
            'password' => 'TempPass123!',
            'password_confirmation' => 'TempPass123!',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'somchai@thailife.test']);
    }

    public function test_registration_through_a_recruit_link_is_refused_for_a_closed_company(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $link = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
        ]);

        $this->postJson('/api/v1/register/resolve-ref-token', ['ref_token' => $link->token])->assertOk();

        $company->delete(); // the soft-delete half, for variety and coverage.

        $this->postJson('/api/v1/register/resolve-ref-token', ['ref_token' => $link->token])
            ->assertNotFound();

        $this->postJson('/api/v1/register', [
            'ref_token' => $link->token,
            'first_name' => 'Somsri',
            'last_name' => 'Dee',
            'email' => 'somsri@thailife.test',
            'id_document_type' => 'thai_national_id',
            'national_id' => '1234567890121',
            'password' => 'TempPass123!',
            'password_confirmation' => 'TempPass123!',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'somsri@thailife.test']);
        $this->assertSame(0, (int) $link->fresh()->used_count);
    }

    /**
     * THE RACE (RegistrationService's in-lock re-check).
     *
     * registerViaRecruitLink() checks the tenant TWICE: once in the unlocked
     * pre-check (resolveRefToken) and once inside the row lock, next to the
     * quota re-check TASK-119 added. The second one is not redundant — it is
     * the only one that holds if an Admin deactivates the company in the
     * window between them — but it is also unreachable in a normal test,
     * because the pre-check answers first. Mutation-testing this file showed
     * exactly that: deleting the in-lock line left every other test green.
     *
     * So the window is reproduced deliberately. DB::listen fires after a query
     * has run and before the calling code continues, and the two reads of
     * `agent_invite_links` are distinguishable by their WHERE clause: the
     * pre-check looks the link up BY TOKEN, the in-lock re-read looks it up BY
     * ID. Flipping the company off on the by-id read lands precisely between
     * the two tenant checks. The write is issued on a second connection so it
     * is not swallowed by the transaction being rolled back around it.
     */
    public function test_a_company_deactivated_mid_registration_is_caught_by_the_in_lock_recheck(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $link = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
        ]);

        $flipped = false;

        DB::listen(function ($query) use ($company, &$flipped) {
            if ($flipped) {
                return;
            }

            // The IN-LOCK re-read: `... from "agent_invite_links" where "id" = ?`.
            // The pre-check's read is by "token" and must NOT match here, or
            // this test would be exercising the pre-check all over again.
            if (! str_contains($query->sql, 'agent_invite_links') || ! str_contains($query->sql, '"id" =')) {
                return;
            }

            $flipped = true;

            DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);
        });

        $this->postJson('/api/v1/register', [
            'ref_token' => $link->token,
            'first_name' => 'Somsak',
            'last_name' => 'Racy',
            'email' => 'somsak@thailife.test',
            'id_document_type' => 'thai_national_id',
            'national_id' => '1234567890121',
            'password' => 'TempPass123!',
            'password_confirmation' => 'TempPass123!',
        ])->assertStatus(422);

        $this->assertTrue($flipped, 'The in-lock re-read never happened — this test is not exercising what it claims.');
        $this->assertDatabaseMissing('users', ['email' => 'somsak@thailife.test']);
        $this->assertSame(0, (int) $link->fresh()->used_count);
    }

    /**
     * The resend endpoint must stay a 200-for-everything non-oracle (its whole
     * design), so the refusal is SILENT: same status, same body, no mail.
     */
    public function test_resend_verification_sends_nothing_for_a_closed_company_but_answers_identically(): void
    {
        Notification::fake();

        $company = Company::factory()->create(['is_active' => false]);
        $code = CompanyInviteCode::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->agent()->pendingApproval()->unverified()->create([
            'company_id' => $company->id,
            'registered_via_invite_code_id' => $code->id,
        ]);

        $real = $this->postJson('/api/v1/register/resend-verification-email', ['email' => $user->email]);
        $unknown = $this->postJson('/api/v1/register/resend-verification-email', ['email' => 'nobody@thailife.test']);

        $real->assertOk();
        $unknown->assertOk();
        $this->assertSame($real->json(), $unknown->json());

        Notification::assertNothingSent();
    }

    /**
     * GET /api/v1/register/verify-email/{id}/{hash} — 403 here rather than the
     * 404 the token-based endpoints use, because this link is signed and
     * addressed to one known person: there is no enumeration boundary to
     * protect and no reason to leave them guessing.
     */
    public function test_email_verification_is_refused_for_a_closed_company(): void
    {
        $company = Company::factory()->create(['is_active' => false]);
        $code = CompanyInviteCode::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->agent()->pendingApproval()->unverified()->create([
            'company_id' => $company->id,
            'registered_via_invite_code_id' => $code->id,
        ]);

        $url = URL::temporarySignedRoute(
            'registration.verify-email',
            now()->addHour(),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
        );

        $this->getJson($url)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    /** A public endpoint of a LIVE company keeps working — no blanket refusal. */
    public function test_public_endpoints_of_an_operational_company_are_untouched(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $code = CompanyInviteCode::factory()->create(['company_id' => $company->id]);

        $this->getJson('/api/v1/public/theme/'.$company->slug)->assertOk();
        $this->postJson('/api/v1/register/resolve-invite-code', ['invite_code' => $code->code])
            ->assertOk()
            ->assertJsonPath('company_name', $company->name);
    }

    // ── The predicate itself ──────────────────────────────────────────────

    /**
     * §3.1 — both halves are required and neither is redundant. Asserted
     * directly on the predicate as well as through the endpoints, because a
     * future refactor that drops one condition would otherwise only show up as
     * a distant HTTP failure.
     */
    public function test_the_operational_predicate_requires_both_active_and_not_deleted(): void
    {
        $live = Company::factory()->create(['is_active' => true]);
        $inactive = Company::factory()->create(['is_active' => false]);
        $deleted = Company::factory()->create(['is_active' => true]);
        $deleted->delete();

        $this->assertTrue($live->isOperational());
        $this->assertFalse($inactive->isOperational());
        $this->assertFalse($deleted->fresh()->isOperational());

        // Fail closed on a company_id that resolves to nothing.
        $this->assertFalse(Company::isOperationalById(null));
        $this->assertFalse(Company::isOperationalById(999999));
        $this->assertTrue(Company::isOperationalById($live->id));
    }

    /**
     * The Super Admin exemption lives in ONE place and is keyed on the role,
     * not merely on "has no company" — so a hypothetical tenant-less Agent
     * still fails closed rather than inheriting the exemption.
     */
    public function test_only_a_super_admin_is_exempted_by_having_no_company(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $orphanAgent = User::factory()->agent()->create(['company_id' => null]);

        $this->assertTrue($superAdmin->belongsToOperationalCompany());
        $this->assertFalse($orphanAgent->belongsToOperationalCompany());
    }
}
