<?php

namespace Tests\Feature\Registration;

use App\Enums\AgentApprovalStatus;
use App\Enums\CommissionPlanType;
use App\Models\AgentInviteLink;
use App\Models\CommissionMatrixSetting;
use App\Models\Company;
use App\Models\CompanyInviteCode;
use App\Models\User;
use App\Notifications\VerifyRegistrationEmailNotification;
use App\Services\Registration\RegistrationService;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * TASK-114 / ADR-025 §4, §5, §6 — registering through a team leader's
 * recruit link.
 *
 * Complements EmailPasswordRegistrationTest (TASK-018), which still owns
 * the invite-code path end to end; only the "nothing changed for it"
 * regression lives here. AgentInviteLinkSchemaTest owns isUsable() at the
 * data layer and AgentInviteLinkTest owns minting/listing/revoking.
 */
class RecruitLinkRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: AgentInviteLink} */
    private function companyLeaderAndLink(array $linkAttributes = []): array
    {
        $company = Company::factory()->create(['name' => 'Thai Life']);
        $leader = User::factory()->agent()->teamLeader()->create([
            'company_id' => $company->id,
            'first_name' => 'Somchai',
            'last_name' => 'Leader',
        ]);
        $link = AgentInviteLink::factory()->create(array_merge([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
        ], $linkAttributes));

        return [$company, $leader, $link];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    // TASK-122 — an identity document is now mandatory on this path.
    // A real Thai national ID (mod-11 checksum verified). Tests that
    // register MORE THAN ONE recruit into the same company must override
    // `national_id` per recruit: one document may register only once per
    // company (RegistrationService::assertDocumentNotAlreadyUsed()), which
    // is the point of that rule, not an inconvenience.
    // IdDocumentRegistrationTest owns the new behaviour in full.
    private const VALID_THAI_ID = '1101700230708';

    private function recruitPayload(string $refToken, array $overrides = []): array
    {
        return array_merge([
            'ref_token' => $refToken,
            'first_name' => 'Somsri',
            'last_name' => 'Recruit',
            'email' => 'somsri@example.com',
            'phone' => '0812345678',
            'id_document_type' => 'thai_national_id',
            'national_id' => self::VALID_THAI_ID,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides);
    }

    private function recruit(): ?User
    {
        return User::withoutGlobalScopes()->where('email', 'somsri@example.com')->first();
    }

    // ══ POST /register/resolve-ref-token ═══════════════════════════════

    /**
     * Deliverable 1. The assertion that matters most here is the NEGATIVE
     * one: exactly two keys come back. AgentInviteLinkResource (the
     * authenticated view) carries token / used_count / max_uses /
     * expires_at / id, and its own docblock says it must not be reused
     * here — this test is what fails if someone does reuse it.
     */
    public function test_resolve_ref_token_returns_only_the_company_and_the_inviter_name(): void
    {
        [, , $link] = $this->companyLeaderAndLink(['max_uses' => 5, 'used_count' => 2, 'label' => 'Open House']);

        $response = $this->postJson('/api/v1/register/resolve-ref-token', ['ref_token' => $link->token])
            ->assertOk()
            ->assertJsonPath('company_name', 'Thai Life')
            ->assertJsonPath('inviter_name', 'Somchai Leader');

        $keys = array_keys($response->json());
        sort($keys);
        $this->assertSame(['company_name', 'inviter_name'], $keys);

        // Spelled out individually so a future regression names itself.
        $body = $response->getContent();
        $this->assertStringNotContainsString($link->token, $body);
        $this->assertStringNotContainsString('used_count', $body);
        $this->assertStringNotContainsString('max_uses', $body);
        $this->assertStringNotContainsString('expires_at', $body);
        $this->assertStringNotContainsString('Open House', $body);
    }

    /**
     * Every failure mode must be indistinguishable from every other, or an
     * anonymous caller can probe a leader's recruiting state (ADR-005's
     * generic-message rule, inherited).
     */
    public function test_resolve_ref_token_404s_identically_for_every_unusable_token(): void
    {
        [$company, $leader] = $this->companyLeaderAndLink();

        $expired = AgentInviteLink::factory()->expired()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);
        $revoked = AgentInviteLink::factory()->revoked()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);
        $exhausted = AgentInviteLink::factory()->quotaReached()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);

        $unknown = $this->postJson('/api/v1/register/resolve-ref-token', ['ref_token' => 'no-such-token'])->assertNotFound();

        foreach ([$expired, $revoked, $exhausted] as $link) {
            $response = $this->postJson('/api/v1/register/resolve-ref-token', ['ref_token' => $link->token])->assertNotFound();
            $this->assertSame($unknown->json('message'), $response->json('message'));
        }
    }

    /**
     * ag-lead ruling on TASK-114 item 5, via the resolver. The check lives
     * in RegistrationService::resolveActiveInviter(), NOT in isUsable() —
     * so this test deliberately also asserts the link itself still reports
     * isUsable() === true. If someone "simplifies" by folding the inviter
     * check into isUsable(), that assertion is what tells them the split
     * was intentional (AgentInviteLinkResource would start N+1-ing).
     */
    public function test_resolve_ref_token_404s_when_the_inviter_can_no_longer_recruit(): void
    {
        // (a) soft-deleted inviter
        [, $deactivatedLeader, $linkA] = $this->companyLeaderAndLink();
        $deactivatedLeader->delete();

        // (b) is_team_leader revoked
        [, $exLeader, $linkB] = $this->companyLeaderAndLink();
        $exLeader->update(['is_team_leader' => false]);

        // (c) inviter moved to another company
        [, $movedLeader, $linkC] = $this->companyLeaderAndLink();
        $movedLeader->update(['company_id' => Company::factory()->create()->id]);

        foreach ([$linkA, $linkB, $linkC] as $link) {
            // The LINK's own three columns are all still fine...
            $this->assertTrue($link->fresh()->isUsable());
            // ...and yet it must not resolve.
            $this->postJson('/api/v1/register/resolve-ref-token', ['ref_token' => $link->token])->assertNotFound();
        }
    }

    public function test_resolve_ref_token_requires_a_token_and_is_rate_limited(): void
    {
        $this->postJson('/api/v1/register/resolve-ref-token', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ref_token');

        // The first call above never reached the throttle (validation 422
        // still counts against it), so 9 more get us to the limit of 10.
        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/v1/register/resolve-ref-token', ['ref_token' => 'x'])->assertNotFound();
        }

        $this->postJson('/api/v1/register/resolve-ref-token', ['ref_token' => 'x'])->assertStatus(429);
    }

    // ══ POST /register — happy path ════════════════════════════════════

    /** Deliverable 3: all four server-derived fields, plus the unchanged approval/verification state. */
    public function test_registering_through_a_link_sets_company_manager_attribution_and_pending_state(): void
    {
        Notification::fake();

        [$company, $leader, $link] = $this->companyLeaderAndLink(['max_uses' => 3]);

        $this->postJson('/api/v1/register', $this->recruitPayload($link->token))
            ->assertCreated();

        $recruit = $this->recruit();
        $this->assertNotNull($recruit);

        $this->assertSame($company->id, $recruit->company_id);
        $this->assertSame($leader->id, $recruit->manager_id);
        $this->assertSame($link->id, $recruit->recruited_via_agent_link_id);
        // The invite-code column stays null — the two channels never mix
        // (ADR-025 §5: the link REPLACES the code).
        $this->assertNull($recruit->registered_via_invite_code_id);

        // Unchanged from the invite-code flow.
        $this->assertSame(AgentApprovalStatus::Pending, $recruit->agent_approval_status);
        $this->assertNull($recruit->email_verified_at);
        Notification::assertSentTo($recruit, VerifyRegistrationEmailNotification::class);

        // Quota consumed exactly once.
        $this->assertSame(1, $link->fresh()->used_count);
    }

    public function test_a_link_with_both_limits_null_admits_several_recruits(): void
    {
        Notification::fake();

        [, , $link] = $this->companyLeaderAndLink(); // factory default: expires_at null, max_uses null.

        // Two different people => two different identity documents (TASK-122
        // — the same one twice in one company is a 422 by design).
        $this->postJson('/api/v1/register', $this->recruitPayload($link->token, [
            'email' => 'a@example.com',
            'id_document_type' => 'passport',
            'national_id' => 'AA123456',
        ]))->assertCreated();
        $this->postJson('/api/v1/register', $this->recruitPayload($link->token, [
            'email' => 'b@example.com',
            'id_document_type' => 'passport',
            'national_id' => 'BB123456',
        ]))->assertCreated();

        $this->assertSame(2, $link->fresh()->used_count);
    }

    // ══ POST /register — rejection paths ═══════════════════════════════

    public function test_expired_revoked_exhausted_and_unknown_tokens_are_rejected_with_422(): void
    {
        Notification::fake();

        [$company, $leader] = $this->companyLeaderAndLink();

        $tokens = [
            'unknown' => 'no-such-token',
            'expired' => AgentInviteLink::factory()->expired()->create(['company_id' => $company->id, 'agent_id' => $leader->id])->token,
            'revoked' => AgentInviteLink::factory()->revoked()->create(['company_id' => $company->id, 'agent_id' => $leader->id])->token,
            'exhausted' => AgentInviteLink::factory()->quotaReached()->create(['company_id' => $company->id, 'agent_id' => $leader->id])->token,
        ];

        foreach ($tokens as $case => $token) {
            $this->postJson('/api/v1/register', $this->recruitPayload($token, ['email' => "{$case}@example.com"]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('ref_token');

            $this->assertNull(User::withoutGlobalScopes()->where('email', "{$case}@example.com")->first());
        }
    }

    /** ag-lead ruling on TASK-114 item 5, via the registration path this time. */
    public function test_a_link_whose_inviter_can_no_longer_recruit_is_rejected(): void
    {
        Notification::fake();

        [, $deactivatedLeader, $linkA] = $this->companyLeaderAndLink();
        $deactivatedLeader->delete();

        [, $exLeader, $linkB] = $this->companyLeaderAndLink();
        $exLeader->update(['is_team_leader' => false]);

        [, $movedLeader, $linkC] = $this->companyLeaderAndLink();
        $movedLeader->update(['company_id' => Company::factory()->create()->id]);

        foreach ([$linkA, $linkB, $linkC] as $index => $link) {
            $this->postJson('/api/v1/register', $this->recruitPayload($link->token, ['email' => "case{$index}@example.com"]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('ref_token');

            $this->assertSame(0, $link->fresh()->used_count);
            $this->assertNull(User::withoutGlobalScopes()->where('email', "case{$index}@example.com")->first());
        }
    }

    // ══ Mutual exclusion (ADR-025 §5) ══════════════════════════════════

    public function test_supplying_both_an_invite_code_and_a_ref_token_is_rejected(): void
    {
        Notification::fake();

        [$company, , $link] = $this->companyLeaderAndLink();
        $inviteCode = CompanyInviteCode::factory()->create(['company_id' => $company->id]);

        $this->postJson('/api/v1/register', $this->recruitPayload($link->token, [
            'invite_code' => $inviteCode->code,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invite_code', 'ref_token']);

        $this->assertNull($this->recruit());
        $this->assertSame(0, $link->fresh()->used_count);
    }

    public function test_supplying_neither_an_invite_code_nor_a_ref_token_is_rejected(): void
    {
        $payload = $this->recruitPayload('irrelevant');
        unset($payload['ref_token']);

        $this->postJson('/api/v1/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invite_code', 'ref_token']);

        $this->assertNull($this->recruit());
    }

    // ══ Nothing in the body may decide identity (acceptance criterion) ══

    public function test_company_id_manager_id_and_approval_status_in_the_body_are_ignored(): void
    {
        Notification::fake();

        [$company, $leader, $link] = $this->companyLeaderAndLink();

        $otherCompany = Company::factory()->create();
        $otherAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);
        $otherLink = AgentInviteLink::factory()->create(['company_id' => $otherCompany->id]);

        $this->postJson('/api/v1/register', $this->recruitPayload($link->token, [
            'company_id' => $otherCompany->id,
            'manager_id' => $otherAgent->id,
            'agent_approval_status' => AgentApprovalStatus::Approved->value,
            'recruited_via_agent_link_id' => $otherLink->id,
            'is_team_leader' => true,
            'role' => 'super_admin',
        ]))->assertCreated();

        $recruit = $this->recruit();
        $this->assertNotNull($recruit);

        $this->assertSame($company->id, $recruit->company_id);
        $this->assertSame($leader->id, $recruit->manager_id);
        $this->assertSame($link->id, $recruit->recruited_via_agent_link_id);
        $this->assertSame(AgentApprovalStatus::Pending, $recruit->agent_approval_status);
        $this->assertFalse($recruit->is_team_leader);
        $this->assertSame('agent', $recruit->role->value);
    }

    // ══ Matrix parity with the Admin path (ADR-025 §6) ═════════════════

    /**
     * The acceptance criteria call this out explicitly: a recruit arriving
     * via a link must end up in exactly the state an Admin assigning
     * manager_id through PUT /users/{user} would have produced. On a
     * Matrix-plan company that includes a matrix_placements row — the thing
     * a "just write manager_id into User::create()" implementation silently
     * skips, and which stays invisible until commission runs.
     */
    public function test_a_matrix_plan_company_places_the_recruit_in_its_matrix_tree(): void
    {
        Notification::fake();

        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Matrix->value]);
        CommissionMatrixSetting::factory()->create(['company_id' => $company->id, 'width' => 3, 'depth' => 5]);
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $link = AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);

        $this->postJson('/api/v1/register', $this->recruitPayload($link->token))->assertCreated();

        $recruit = $this->recruit();
        $this->assertNotNull($recruit);

        // Same bootstrap behaviour as MatrixCommissionCalculationTest's
        // "first agent creates the sponsor as root".
        $this->assertDatabaseHas('matrix_placements', ['user_id' => $leader->id, 'parent_id' => null]);
        $this->assertDatabaseHas('matrix_placements', ['user_id' => $recruit->id, 'parent_id' => $leader->id]);
    }

    /**
     * The flip side of the parity decision, asserted so nobody "fixes" it
     * by accident: on a Matrix company with no matrix settings configured,
     * MatrixCommissionService::place() throws — and because the whole
     * registration is one transaction, the user, the manager assignment
     * AND the consumed quota slot all roll back together. No half-created
     * recruit, no leaked quota.
     */
    public function test_a_failure_inside_the_transaction_rolls_back_the_user_and_the_consumed_quota(): void
    {
        Notification::fake();

        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Matrix->value]);
        // Deliberately NO CommissionMatrixSetting row.
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $link = AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leader->id, 'max_uses' => 1]);

        $this->postJson('/api/v1/register', $this->recruitPayload($link->token))->assertUnprocessable();

        $this->assertNull($this->recruit());
        $this->assertSame(0, $link->fresh()->used_count);
    }

    // ══ ADR-025 §4 — the quota can never be exceeded ═══════════════════

    /** Sequential, no race: the plain "second person is too late" case. */
    public function test_a_max_uses_one_link_admits_exactly_one_recruit(): void
    {
        Notification::fake();

        [, , $link] = $this->companyLeaderAndLink(['max_uses' => 1]);

        $this->postJson('/api/v1/register', $this->recruitPayload($link->token, ['email' => 'first@example.com']))
            ->assertCreated();

        $this->postJson('/api/v1/register', $this->recruitPayload($link->token, ['email' => 'second@example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ref_token');

        $fresh = $link->fresh();
        $this->assertSame(1, $fresh->used_count);
        $this->assertSame($fresh->max_uses, $fresh->used_count); // never exceeded
        $this->assertNotNull(User::withoutGlobalScopes()->where('email', 'first@example.com')->first());
        $this->assertNull(User::withoutGlobalScopes()->where('email', 'second@example.com')->first());
    }

    /**
     * THE CONCURRENCY TEST — read this docblock before trusting it.
     *
     * WHAT IT DOES. It cannot run two requests in parallel (PHPUnit is
     * single-process and the suite runs on an in-memory SQLite database —
     * a second connection would be a different database entirely), so it
     * simulates the interleaving instead. A one-shot listener on
     * TransactionBeginning fires at the exact moment
     * registerViaRecruitLink() opens its transaction — i.e. AFTER the Form
     * Request's check and the Service's pre-check have both already passed
     * against used_count = 0, and BEFORE the locked re-read. The listener
     * consumes the last remaining use, standing in for a competing request
     * that committed in that window. The registration must then be
     * rejected.
     *
     * WHAT IT PROVES. That the in-lock re-check actually re-reads the row
     * rather than trusting the value the pre-check saw, and that a
     * registration rejected there writes nothing. Delete the re-check
     * inside the transaction and this test fails with a 201 and
     * used_count = 2 against max_uses = 1 — which is precisely the defect
     * ADR-025 §4 exists to prevent.
     *
     * WHAT IT DOES **NOT** PROVE.
     *   1. That `lockForUpdate()` serialises anything. On SQLite Laravel's
     *      grammar compiles it to an empty string, so the lock is a no-op
     *      here regardless of whether the call is present. Only MySQL 8
     *      (the real target, CLAUDE.md §3) enforces it.
     *   2. That two genuinely simultaneous HTTP requests behave. That
     *      needs two processes against MySQL and is TASK-118 test case 2,
     *      run by ag-qa against the real database — it is NOT covered here
     *      and must not be reported as covered (Guardrail 4).
     * A companion guard against the lock call itself being deleted lives
     * in the next test.
     *
     * Note also that the simulated competitor's write shares our
     * transaction (same connection), so it rolls back with us — hence this
     * test asserts "nothing was created", not a final used_count.
     */
    public function test_a_use_consumed_between_the_pre_check_and_the_lock_is_still_rejected(): void
    {
        Notification::fake();

        [, , $link] = $this->companyLeaderAndLink(['max_uses' => 1]);

        $interleaved = false;
        Event::listen(function (TransactionBeginning $event) use ($link, &$interleaved) {
            if ($interleaved) {
                return;
            }
            $interleaved = true;

            // Stand-in for a competing registration that committed between
            // our pre-check and our locked read.
            AgentInviteLink::withoutGlobalScopes()->whereKey($link->id)->update(['used_count' => 1]);
        });

        $this->postJson('/api/v1/register', $this->recruitPayload($link->token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ref_token');

        $this->assertTrue($interleaved, 'The interleaving never happened — this test proved nothing.');
        $this->assertNull($this->recruit());
    }

    /**
     * A SOURCE-LEVEL GUARD, not a behavioural test — stated plainly
     * because it is unusual.
     *
     * On SQLite `lockForUpdate()` compiles to nothing, so no runtime
     * assertion in this suite can tell whether the call is still there.
     * It is, however, the single line that makes the quota safe on MySQL,
     * and ADR-025 §4 calls it "the one place a quota can be defeated by a
     * race". So this asserts the call still exists in the consumption
     * path. It proves the code was not deleted; it proves nothing about
     * whether the database honours it.
     */
    public function test_the_consumption_path_still_takes_a_row_lock(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(RegistrationService::class))->getFileName());

        // `->lockForUpdate()` rather than the bare word, so a comment
        // mentioning the lock cannot satisfy this on its own.
        $this->assertStringContainsString('->lockForUpdate()', $source);
        $this->assertStringContainsString('DB::transaction(', $source);
    }

    // ══ Regression: the invite-code path is untouched ══════════════════

    /**
     * ADR-025 §5 says the link REPLACES the code; it does not change it.
     * EmailPasswordRegistrationTest still owns that flow in full — this is
     * the one assertion that belongs next to the new code, because the
     * mutual-exclusion rules added to RegisterRequest are the thing most
     * likely to break it.
     */
    public function test_registering_with_an_invite_code_alone_still_works_unchanged(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $inviteCode = CompanyInviteCode::factory()->create(['company_id' => $company->id]);

        $payload = $this->recruitPayload('irrelevant', ['invite_code' => $inviteCode->code]);
        unset($payload['ref_token']);

        $this->postJson('/api/v1/register', $payload)->assertCreated();

        $user = $this->recruit();
        $this->assertNotNull($user);
        $this->assertSame($company->id, $user->company_id);
        $this->assertSame($inviteCode->id, $user->registered_via_invite_code_id);
        // The two new columns stay untouched on this path.
        $this->assertNull($user->recruited_via_agent_link_id);
        $this->assertNull($user->manager_id);
        $this->assertSame(AgentApprovalStatus::Pending, $user->agent_approval_status);
    }
}
