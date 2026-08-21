<?php

namespace Tests\Feature\Registration;

use App\Models\AgentInviteLink;
use App\Models\Company;
use App\Models\CompanyInviteCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /register/check-email — the signup form asks whether an address is
 * already an account, before the recruit fills in the rest of the form.
 *
 * ── THE HALF OF THIS FILE THAT IS SECURITY, NOT FEATURE ──
 *
 * This endpoint answers "does this person have an account here", to an
 * anonymous caller, from one field. On a platform whose every account is a
 * commission-earning insurance agent, an unguarded version is a competitor's
 * recruiting list and a phisher's target list, harvestable at machine speed.
 *
 * The guard is that the caller must hold the same LIVE invite code or
 * recruit token that POST /register demands. That is the entire mitigation,
 * it is one `required_without` pair and two lookup closures away from being
 * deleted by accident, and deleting it would not break a single feature
 * test — the form would keep working perfectly. So the tests that pin it are
 * written first and named for what they prevent, not for what they call.
 *
 * ── AND THE HALF THAT IS CORRECTNESS ──
 *
 * A preview that disagrees with the thing it previews is worse than no
 * preview: "available" followed by a 422 on submit reads as a broken site,
 * and "taken" on a free address turns a recruit away for nothing. So the
 * comparison here has to be the SAME one `unique:users,email` makes —
 * including over soft-deleted rows, which it has always seen.
 */
class CheckEmailAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: CompanyInviteCode} */
    private function companyWithInviteCode(): array
    {
        $company = Company::factory()->create();

        return [$company, CompanyInviteCode::factory()->create(['company_id' => $company->id])];
    }

    /** @return array{0: Company, 1: AgentInviteLink} */
    private function companyWithRecruitLink(): array
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);

        return [$company, AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
        ])];
    }

    // ── the enumeration guard ──────────────────────────────────────────

    public function test_an_anonymous_caller_with_no_link_is_refused(): void
    {
        // THE WHOLE POINT. Without this, the endpoint is a membership oracle
        // for the entire platform, callable by anyone who knows the URL.
        User::factory()->agent()->create(['email' => 'somchai@example.com']);

        $this->postJson('/api/v1/register/check-email', ['email' => 'somchai@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invite_code', 'ref_token']);
    }

    public function test_an_invented_invite_code_is_refused(): void
    {
        // An attacker who guesses that a code is required must not get in by
        // sending any string at all.
        $this->postJson('/api/v1/register/check-email', [
            'email' => 'somchai@example.com',
            'invite_code' => 'NOT-A-REAL-CODE',
        ])->assertUnprocessable()->assertJsonValidationErrors('invite_code');
    }

    public function test_an_invented_recruit_token_is_refused(): void
    {
        $this->postJson('/api/v1/register/check-email', [
            'email' => 'somchai@example.com',
            'ref_token' => str_repeat('a', 64),
        ])->assertUnprocessable()->assertJsonValidationErrors('ref_token');
    }

    public function test_a_revoked_recruit_link_stops_answering(): void
    {
        // Revoking a link is the lever a company has when a link is being
        // abused. If revocation stopped registrations but not this endpoint,
        // that lever would be half-connected — and the half still working
        // would be the half that leaks.
        [, $link] = $this->companyWithRecruitLink();
        $link->update(['revoked_at' => now()]);

        $this->postJson('/api/v1/register/check-email', [
            'email' => 'somchai@example.com',
            'ref_token' => $link->token,
        ])->assertUnprocessable()->assertJsonValidationErrors('ref_token');
    }

    public function test_the_refusal_never_reveals_whether_the_account_exists(): void
    {
        // A caller without a link must not be able to read the answer out of
        // the SHAPE of the rejection. Both requests below have to be
        // indistinguishable, or the guard is decorative.
        User::factory()->agent()->create(['email' => 'real@example.com']);

        $existing = $this->postJson('/api/v1/register/check-email', ['email' => 'real@example.com']);
        $unknown = $this->postJson('/api/v1/register/check-email', ['email' => 'nobody@example.com']);

        $this->assertSame($existing->status(), $unknown->status());
        $this->assertSame($existing->json(), $unknown->json());
    }

    // ── the answer itself ──────────────────────────────────────────────

    public function test_a_free_address_is_reported_available(): void
    {
        [, $code] = $this->companyWithInviteCode();

        $this->postJson('/api/v1/register/check-email', [
            'email' => 'nobody@example.com',
            'invite_code' => $code->code,
        ])->assertOk()->assertExactJson(['available' => true]);
    }

    public function test_a_taken_address_is_reported_taken(): void
    {
        [, $code] = $this->companyWithInviteCode();
        User::factory()->agent()->create(['email' => 'somchai@example.com']);

        $this->postJson('/api/v1/register/check-email', [
            'email' => 'somchai@example.com',
            'invite_code' => $code->code,
        ])->assertOk()->assertExactJson(['available' => false]);
    }

    public function test_the_recruit_link_path_answers_too(): void
    {
        [, $link] = $this->companyWithRecruitLink();
        User::factory()->agent()->create(['email' => 'somchai@example.com']);

        $this->postJson('/api/v1/register/check-email', [
            'email' => 'somchai@example.com',
            'ref_token' => $link->token,
        ])->assertOk()->assertExactJson(['available' => false]);
    }

    public function test_an_address_taken_in_another_company_is_still_taken(): void
    {
        // Email is unique platform-wide, not per company — it is the login
        // identity. A per-company answer here would tell a recruit their
        // address is free and then refuse them at submit.
        [, $code] = $this->companyWithInviteCode();
        $elsewhere = Company::factory()->create();
        User::factory()->agent()->create([
            'company_id' => $elsewhere->id,
            'email' => 'somchai@example.com',
        ]);

        $this->postJson('/api/v1/register/check-email', [
            'email' => 'somchai@example.com',
            'invite_code' => $code->code,
        ])->assertOk()->assertExactJson(['available' => false]);
    }

    public function test_a_deactivated_agents_address_is_still_taken(): void
    {
        // `unique:users,email` is a plain SQL check and has always seen
        // soft-deleted rows, so the submit will refuse this address. If the
        // preview said "available", the form would wave the recruit through
        // to a 422 it had just promised would not happen.
        [, $code] = $this->companyWithInviteCode();
        $agent = User::factory()->agent()->create(['email' => 'gone@example.com']);
        $agent->delete();

        $this->postJson('/api/v1/register/check-email', [
            'email' => 'gone@example.com',
            'invite_code' => $code->code,
        ])->assertOk()->assertExactJson(['available' => false]);
    }

    public function test_the_answer_carries_nothing_but_the_answer(): void
    {
        // No name, no company, no registered_at. Whether the account exists
        // is the entire question; anything else turns a yes/no into a
        // profile of a real agent, handed to an anonymous caller.
        [, $code] = $this->companyWithInviteCode();
        User::factory()->agent()->create([
            'email' => 'somchai@example.com',
            'first_name' => 'Somchai',
            'last_name' => 'Agent',
        ]);

        $response = $this->postJson('/api/v1/register/check-email', [
            'email' => 'somchai@example.com',
            'invite_code' => $code->code,
        ])->assertOk();

        $this->assertSame(['available'], array_keys($response->json()));
        $this->assertStringNotContainsString('Somchai', $response->getContent());
    }

    // ── shape ──────────────────────────────────────────────────────────

    public function test_a_malformed_address_is_rejected_rather_than_looked_up(): void
    {
        [, $code] = $this->companyWithInviteCode();

        $this->postJson('/api/v1/register/check-email', [
            'email' => 'not-an-email',
            'invite_code' => $code->code,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_sending_both_credentials_at_once_is_rejected(): void
    {
        // Mirrors RegisterRequest. Accepting both would let a caller pair a
        // live code with a dead token (or the reverse) and have the request
        // pass on whichever one happened to be checked.
        [, $code] = $this->companyWithInviteCode();
        [, $link] = $this->companyWithRecruitLink();

        $this->postJson('/api/v1/register/check-email', [
            'email' => 'nobody@example.com',
            'invite_code' => $code->code,
            'ref_token' => $link->token,
        ])->assertUnprocessable();
    }

    public function test_it_never_creates_anything(): void
    {
        // It is a POST because the address must not end up in a URL, a
        // server log or a Referer header (§6, PDPA) — not because it writes.
        [, $code] = $this->companyWithInviteCode();
        $before = User::withTrashed()->count();

        $this->postJson('/api/v1/register/check-email', [
            'email' => 'nobody@example.com',
            'invite_code' => $code->code,
        ])->assertOk();

        $this->assertSame($before, User::withTrashed()->count());
    }
}
