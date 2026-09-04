<?php

namespace Tests\Feature\Registration;

use App\Enums\IdDocumentType;
use App\Models\AgentInviteLink;
use App\Models\Company;
use App\Models\CompanyInviteCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * TASK-122 — an identity document (Thai national ID or passport) is
 * MANDATORY on both self-registration paths, is unique per company, and is
 * hashed in a type-aware way.
 *
 * Scope split with its neighbours: EmailPasswordRegistrationTest and
 * RecruitLinkRegistrationTest still own their own paths end to end (they
 * were updated only to carry the new required fields);
 * UserNationalIdSearchTest owns the Admin-side storage/masking/search of the
 * SAME columns. What lives here is everything the new requirement adds.
 */
class IdDocumentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** Real Thai national ID — first 12 digits 110170023070, check digit 8. */
    private const VALID_THAI_ID = '1101700230708';

    /** Same first 12 digits, wrong check digit. */
    private const BAD_CHECKSUM_THAI_ID = '1101700230700';

    // ── fixtures ───────────────────────────────────────────────────────

    /** @return array{0: Company, 1: CompanyInviteCode} */
    private function companyWithInviteCode(): array
    {
        $company = Company::factory()->create();

        return [$company, CompanyInviteCode::factory()->create(['company_id' => $company->id])];
    }

    /** @return array{0: Company, 1: User, 2: AgentInviteLink} */
    private function companyWithRecruitLink(): array
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->teamLeader()->create(['company_id' => $company->id]);
        $link = AgentInviteLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
        ]);

        return [$company, $leader, $link];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Somsri',
            'last_name' => 'Applicant',
            'email' => 'somsri@example.com',
            'phone' => '0812345678',
            'id_document_type' => IdDocumentType::ThaiNationalId->value,
            'national_id' => self::VALID_THAI_ID,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides);
    }

    private function findByEmail(string $email): ?User
    {
        return User::withoutGlobalScopes()->where('email', $email)->first();
    }

    // ══ Happy paths — each document type, on each registration path ════

    public function test_invite_code_registration_stores_a_thai_national_id_with_its_type(): void
    {
        Notification::fake();
        [$company, $inviteCode] = $this->companyWithInviteCode();

        $this->postJson('/api/v1/register', $this->payload(['invite_code' => $inviteCode->code]))
            ->assertCreated();

        $user = $this->findByEmail('somsri@example.com');
        $this->assertNotNull($user);
        $this->assertSame($company->id, $user->company_id);
        $this->assertSame(self::VALID_THAI_ID, $user->national_id);
        $this->assertSame(IdDocumentType::ThaiNationalId, $user->id_document_type);
        // The blind index is derived, and derived from the RIGHT branch.
        $this->assertSame(
            User::hashNationalId(self::VALID_THAI_ID, IdDocumentType::ThaiNationalId),
            $user->national_id_hash,
        );
        // §6 PDPA — the column itself is ciphertext, never the number.
        $this->assertNotSame(
            self::VALID_THAI_ID,
            DB::table('users')->where('id', $user->id)->value('national_id'),
        );
    }

    public function test_invite_code_registration_stores_a_passport_with_its_type(): void
    {
        Notification::fake();
        [, $inviteCode] = $this->companyWithInviteCode();

        $this->postJson('/api/v1/register', $this->payload([
            'invite_code' => $inviteCode->code,
            'id_document_type' => IdDocumentType::Passport->value,
            'national_id' => 'AB1234567',
        ]))->assertCreated();

        $user = $this->findByEmail('somsri@example.com');
        $this->assertNotNull($user);
        $this->assertSame('AB1234567', $user->national_id);
        $this->assertSame(IdDocumentType::Passport, $user->id_document_type);
        $this->assertSame(
            User::hashNationalId('AB1234567', IdDocumentType::Passport),
            $user->national_id_hash,
        );
    }

    public function test_recruit_link_registration_stores_a_thai_national_id_with_its_type(): void
    {
        Notification::fake();
        [$company, , $link] = $this->companyWithRecruitLink();

        $this->postJson('/api/v1/register', $this->payload(['ref_token' => $link->token]))
            ->assertCreated();

        $user = $this->findByEmail('somsri@example.com');
        $this->assertNotNull($user);
        $this->assertSame($company->id, $user->company_id);
        $this->assertSame(self::VALID_THAI_ID, $user->national_id);
        $this->assertSame(IdDocumentType::ThaiNationalId, $user->id_document_type);
    }

    public function test_recruit_link_registration_stores_a_passport_with_its_type(): void
    {
        Notification::fake();
        [, , $link] = $this->companyWithRecruitLink();

        $this->postJson('/api/v1/register', $this->payload([
            'ref_token' => $link->token,
            'id_document_type' => IdDocumentType::Passport->value,
            'national_id' => 'ZZ998877',
        ]))->assertCreated();

        $user = $this->findByEmail('somsri@example.com');
        $this->assertNotNull($user);
        $this->assertSame('ZZ998877', $user->national_id);
        $this->assertSame(IdDocumentType::Passport, $user->id_document_type);
    }

    // ══ THE regression decision 4 exists to prevent ════════════════════

    /**
     * THE test this task's hashing change exists for.
     *
     * Before TASK-122, hashNationalId() ran preg_replace('/\D/', '', ...)
     * unconditionally. Under that algorithm "AA1234567" and "ZZ1234567" both
     * normalize to "1234567" and hash IDENTICALLY — so the second of these
     * two DIFFERENT PEOPLE would be turned away as a duplicate, and the
     * /users search would return the wrong one. Revert the Passport branch
     * of User::hashNationalId() and this test fails with a 422.
     */
    public function test_two_passports_differing_only_in_their_letters_are_not_duplicates(): void
    {
        Notification::fake();
        [, $inviteCode] = $this->companyWithInviteCode();

        foreach ([['aa@example.com', 'AA1234567'], ['zz@example.com', 'ZZ1234567']] as [$email, $passport]) {
            $this->postJson('/api/v1/register', $this->payload([
                'invite_code' => $inviteCode->code,
                'email' => $email,
                'id_document_type' => IdDocumentType::Passport->value,
                'national_id' => $passport,
            ]))->assertCreated();
        }

        $first = $this->findByEmail('aa@example.com');
        $second = $this->findByEmail('zz@example.com');
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        // Stated as its own assertion: the whole failure mode was two rows
        // sharing one hash.
        $this->assertNotSame($first->national_id_hash, $second->national_id_hash);
    }

    /**
     * The flip side: within ONE document type, formatting is not identity.
     * Case and separators are canonicalised away, so these ARE the same
     * passport and the second attempt must be refused.
     */
    public function test_a_passport_is_matched_regardless_of_case_and_separators(): void
    {
        Notification::fake();
        [, $inviteCode] = $this->companyWithInviteCode();

        $this->postJson('/api/v1/register', $this->payload([
            'invite_code' => $inviteCode->code,
            'id_document_type' => IdDocumentType::Passport->value,
            'national_id' => 'AB1234567',
        ]))->assertCreated();

        $this->postJson('/api/v1/register', $this->payload([
            'invite_code' => $inviteCode->code,
            'email' => 'second@example.com',
            'id_document_type' => IdDocumentType::Passport->value,
            'national_id' => 'ab1234567',
        ]))->assertUnprocessable()->assertJsonValidationErrors('national_id');

        $this->assertNull($this->findByEmail('second@example.com'));
    }

    // ══ Backward compatibility of the hash ═════════════════════════════

    /**
     * Every row that predates this task has id_document_type = null and a
     * hash derived by the ORIGINAL digits-only algorithm. Those hashes must
     * keep matching, or every existing agent silently becomes unsearchable
     * and un-deduplicable.
     *
     * The expectation is spelled out as the literal pre-TASK-122 expression
     * rather than as a call to the method under test — otherwise this would
     * assert only that the code agrees with itself.
     */
    public function test_a_pre_existing_thai_id_hash_still_matches_after_the_type_aware_change(): void
    {
        $company = Company::factory()->create();
        // No id_document_type — exactly the shape of a legacy row.
        $legacy = User::factory()->agent()->create([
            'company_id' => $company->id,
            'national_id' => self::VALID_THAI_ID,
        ]);

        $preTask122Hash = hash_hmac('sha256', self::VALID_THAI_ID, (string) config('app.key'));

        $this->assertNull($legacy->id_document_type);
        $this->assertSame($preTask122Hash, $legacy->national_id_hash);
        // And the two ways the code can ask for it agree with that value.
        $this->assertSame($preTask122Hash, User::hashNationalId(self::VALID_THAI_ID));
        $this->assertSame($preTask122Hash, User::hashNationalId(self::VALID_THAI_ID, IdDocumentType::ThaiNationalId));

        // The legacy row is still findable by the search endpoint.
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->actingAs($admin)->getJson('/api/v1/users?national_id='.self::VALID_THAI_ID)
            ->assertOk()
            ->assertJsonPath('data.0.id', $legacy->id);
    }

    // ══ Duplicate prevention — per company, not globally ═══════════════

    public function test_the_same_document_cannot_register_twice_in_one_company(): void
    {
        Notification::fake();
        [, $inviteCode] = $this->companyWithInviteCode();

        $this->postJson('/api/v1/register', $this->payload(['invite_code' => $inviteCode->code]))
            ->assertCreated();

        $this->postJson('/api/v1/register', $this->payload([
            'invite_code' => $inviteCode->code,
            'email' => 'second@example.com',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('national_id');

        $this->assertNull($this->findByEmail('second@example.com'));
    }

    /**
     * Same rule on the recruit-link path, where it runs INSIDE the
     * transaction. The extra assertion is the one the placement buys: a
     * rejection must roll the consumed quota slot back with everything else,
     * or a leader loses a use to a registration that never happened.
     */
    public function test_the_same_document_cannot_register_twice_through_a_recruit_link(): void
    {
        Notification::fake();
        [, , $link] = $this->companyWithRecruitLink();

        $this->postJson('/api/v1/register', $this->payload(['ref_token' => $link->token]))
            ->assertCreated();
        $this->assertSame(1, $link->fresh()->used_count);

        $this->postJson('/api/v1/register', $this->payload([
            'ref_token' => $link->token,
            'email' => 'second@example.com',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('national_id');

        $this->assertNull($this->findByEmail('second@example.com'));
        $this->assertSame(1, $link->fresh()->used_count);
    }

    /**
     * BR-6 — the platform is multi-tenant and the same real person may
     * legitimately be an agent at two companies. A global uniqueness rule
     * would both break that and turn this endpoint into an oracle for "is
     * this person an agent somewhere on this platform".
     */
    public function test_the_same_document_may_register_in_a_different_company(): void
    {
        Notification::fake();
        [, $inviteCodeA] = $this->companyWithInviteCode();
        [$companyB, $inviteCodeB] = $this->companyWithInviteCode();

        $this->postJson('/api/v1/register', $this->payload(['invite_code' => $inviteCodeA->code]))
            ->assertCreated();

        $this->postJson('/api/v1/register', $this->payload([
            'invite_code' => $inviteCodeB->code,
            'email' => 'same-person@example.com',
        ]))->assertCreated();

        $second = $this->findByEmail('same-person@example.com');
        $this->assertNotNull($second);
        $this->assertSame($companyB->id, $second->company_id);
    }

    /**
     * A deactivated agent is still that person's account in this company.
     * Documented decision, asserted here so a future "why does withTrashed()
     * matter" question has an answer that fails loudly if removed. It also
     * matches what the other identifier on this form already does —
     * `unique:users,email` has always seen soft-deleted rows.
     */
    public function test_a_deactivated_agents_document_still_blocks_a_new_registration(): void
    {
        Notification::fake();
        [$company, $inviteCode] = $this->companyWithInviteCode();

        $deactivated = User::factory()->agent()->create([
            'company_id' => $company->id,
            'national_id' => self::VALID_THAI_ID,
            'id_document_type' => IdDocumentType::ThaiNationalId->value,
        ]);
        $deactivated->delete();

        $this->postJson('/api/v1/register', $this->payload(['invite_code' => $inviteCode->code]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('national_id');
    }

    /** §6 — the refusal must not identify who already holds the document. */
    public function test_the_duplicate_message_does_not_reveal_the_existing_holder(): void
    {
        Notification::fake();
        [, $inviteCode] = $this->companyWithInviteCode();

        $this->postJson('/api/v1/register', $this->payload([
            'invite_code' => $inviteCode->code,
            'first_name' => 'Existing',
            'last_name' => 'Holder',
        ]))->assertCreated();

        $body = $this->postJson('/api/v1/register', $this->payload([
            'invite_code' => $inviteCode->code,
            'email' => 'second@example.com',
        ]))->assertUnprocessable()->getContent();

        $this->assertStringNotContainsString('Existing', (string) $body);
        $this->assertStringNotContainsString('Holder', (string) $body);
        $this->assertStringNotContainsString('somsri@example.com', (string) $body);
        // Not even the last 4 digits of the number that collided.
        $this->assertStringNotContainsString('0708', (string) $body);
    }

    // ══ Validation ═════════════════════════════════════════════════════

    public function test_registration_without_a_document_type_is_rejected(): void
    {
        [, $inviteCode] = $this->companyWithInviteCode();

        $payload = $this->payload(['invite_code' => $inviteCode->code]);
        unset($payload['id_document_type']);

        $this->postJson('/api/v1/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_document_type');

        $this->assertNull($this->findByEmail('somsri@example.com'));
    }

    public function test_registration_without_a_document_number_is_rejected(): void
    {
        [, $inviteCode] = $this->companyWithInviteCode();

        $payload = $this->payload(['invite_code' => $inviteCode->code]);
        unset($payload['national_id']);

        $this->postJson('/api/v1/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('national_id');
    }

    /**
     * 2026-08-27 (human decision) — OMITTING THE DOCUMENT ENTIRELY IS NOW OK.
     *
     * This test used to assert the opposite, and it asserted it for a good
     * reason: two public registration endpoints that disagree about what
     * identity is required is a hole, not a feature. That reason still
     * holds — what changed is the answer on BOTH paths. The document is no
     * longer asked for at sign-up at all; an agent supplies it from their own
     * profile when there is a payout to make (see ProfileSettingsView and
     * PUT /me/id-document).
     *
     * So what is pinned here is the new rule and the invariant that survived
     * it: nothing required, and the two paths still agree.
     */
    public function test_a_recruit_can_sign_up_with_no_document_at_all(): void
    {
        [, , $link] = $this->companyWithRecruitLink();

        $payload = $this->payload(['ref_token' => $link->token]);
        unset($payload['id_document_type'], $payload['national_id']);

        $this->postJson('/api/v1/register', $payload)->assertCreated();

        $recruit = $this->findByEmail('somsri@example.com');
        $this->assertNotNull($recruit);
        // Not stored as an empty string or a placeholder: absent means null,
        // which is what the profile screen and the duplicate check both read.
        $this->assertNull($recruit->national_id);
        $this->assertNull($recruit->id_document_type);
    }

    /**
     * The pairing rule is what is left of the old requirement, and it still
     * applies to BOTH paths — the invite-code half is two tests above.
     *
     * Half a document is worse than none: `national_id` alone cannot be
     * validated (each type has its own shape rule) and cannot be hashed into
     * the per-company blind index, so it would be stored unvalidated and
     * never matched against anything.
     */
    public function test_half_a_document_is_still_rejected_on_the_recruit_link_path(): void
    {
        [, , $link] = $this->companyWithRecruitLink();

        $payload = $this->payload(['ref_token' => $link->token]);
        unset($payload['id_document_type']);

        $this->postJson('/api/v1/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_document_type');

        // Nothing was created and no quota was spent on a rejected attempt.
        $this->assertNull($this->findByEmail('somsri@example.com'));
        $this->assertSame(0, $link->fresh()->used_count);
    }

    /**
     * The mismatch case: a perfectly valid passport number, declared as a
     * Thai national ID. Each type is validated against its OWN rule, so this
     * must fail on the number — not be quietly accepted because "it looks
     * like an ID of some sort".
     */
    public function test_a_passport_submitted_as_a_thai_national_id_is_rejected(): void
    {
        [, $inviteCode] = $this->companyWithInviteCode();

        $this->postJson('/api/v1/register', $this->payload([
            'invite_code' => $inviteCode->code,
            'id_document_type' => IdDocumentType::ThaiNationalId->value,
            'national_id' => 'AB1234567',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('national_id');
    }

    public function test_a_thai_national_id_with_a_bad_checksum_is_rejected(): void
    {
        [, $inviteCode] = $this->companyWithInviteCode();

        $this->postJson('/api/v1/register', $this->payload([
            'invite_code' => $inviteCode->code,
            'national_id' => self::BAD_CHECKSUM_THAI_ID,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('national_id');
    }

    public function test_a_passport_outside_the_allowed_length_is_rejected(): void
    {
        [, $inviteCode] = $this->companyWithInviteCode();

        foreach (['AB12', 'AB1234567890123', 'AB-12345!'] as $badPassport) {
            $this->postJson('/api/v1/register', $this->payload([
                'invite_code' => $inviteCode->code,
                'id_document_type' => IdDocumentType::Passport->value,
                'national_id' => $badPassport,
            ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('national_id');
        }
    }

    public function test_an_unknown_document_type_is_rejected(): void
    {
        [, $inviteCode] = $this->companyWithInviteCode();

        $this->postJson('/api/v1/register', $this->payload([
            'invite_code' => $inviteCode->code,
            'id_document_type' => 'driving_licence',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_document_type');
    }

    // ══ Admin path — unchanged where it must be ════════════════════════

    /**
     * The asymmetry is deliberate (see StoreUserRequest): an Admin creating
     * an agent on someone's behalf may not have the document to hand, and
     * making it mandatory there would block a legitimate existing workflow.
     */
    public function test_an_admin_can_still_create_an_agent_with_no_document(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'first_name' => 'No',
            'last_name' => 'Document',
            'email' => 'nodoc@example.com',
            'password' => 'Password123',
            'role' => 'agent',
        ])->assertCreated();

        $created = User::withoutGlobalScopes()->where('email', 'nodoc@example.com')->first();
        $this->assertNotNull($created);
        $this->assertNull($created->national_id);
        $this->assertNull($created->id_document_type);
        $this->assertNull($created->national_id_hash);
    }

    public function test_an_admin_supplying_a_number_without_a_type_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'first_name' => 'Missing',
            'last_name' => 'Type',
            'email' => 'missingtype@example.com',
            'password' => 'Password123',
            'role' => 'agent',
            'national_id' => self::VALID_THAI_ID,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_document_type');
    }

    public function test_an_admin_can_record_a_passport_and_it_is_exposed_on_the_resource(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $target = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$target->id}", [
            'id_document_type' => IdDocumentType::Passport->value,
            'national_id' => 'AB1234567',
        ])
            ->assertOk()
            ->assertJsonPath('data.id_document_type', 'passport')
            ->assertJsonPath('data.national_id_masked', '*****4567')
            ->assertJsonPath('data.national_id', 'AB1234567');
    }

    /**
     * A type change alone re-derives the blind index, because the type is
     * half of what the hash means. If the saving hook only watched
     * national_id, this row would keep a stale hash and stop being findable.
     */
    public function test_changing_only_the_document_type_re_derives_the_blind_index(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $target = User::factory()->agent()->create([
            'company_id' => $company->id,
            'national_id' => 'AB1234567',
            'id_document_type' => IdDocumentType::Passport->value,
        ]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$target->id}", [
            'id_document_type' => IdDocumentType::ThaiNationalId->value,
        ])->assertOk();

        $this->assertSame(
            User::hashNationalId('AB1234567', IdDocumentType::ThaiNationalId),
            $target->fresh()->national_id_hash,
        );
    }

    /** §6 audit — the type change is recorded, and the number never is. */
    public function test_a_document_change_is_audit_logged_with_the_type_and_only_the_mask(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $target = User::factory()->agent()->create([
            'company_id' => $company->id,
            'national_id' => self::VALID_THAI_ID,
            'id_document_type' => IdDocumentType::ThaiNationalId->value,
        ]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$target->id}", [
            'id_document_type' => IdDocumentType::Passport->value,
            'national_id' => 'AB1234567',
        ])->assertOk();

        $log = DB::table('audit_logs')
            ->where('action', 'user.national_id_updated')
            ->where('auditable_id', $target->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);

        $old = json_decode((string) $log->old_values, true);
        $new = json_decode((string) $log->new_values, true);

        $this->assertSame('thai_national_id', $old['id_document_type']);
        $this->assertSame('passport', $new['id_document_type']);
        $this->assertSame('*********0708', $old['national_id_masked']);
        $this->assertSame('*****4567', $new['national_id_masked']);

        // Neither raw number may appear anywhere in the entry.
        $this->assertStringNotContainsString(self::VALID_THAI_ID, (string) $log->old_values);
        $this->assertStringNotContainsString('AB1234567', (string) $log->new_values);
    }

    // ══ Search finds both document types ═══════════════════════════════

    public function test_the_search_endpoint_finds_both_document_types(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $thai = User::factory()->agent()->create([
            'company_id' => $company->id,
            'national_id' => self::VALID_THAI_ID,
            'id_document_type' => IdDocumentType::ThaiNationalId->value,
        ]);
        $passport = User::factory()->agent()->create([
            'company_id' => $company->id,
            'national_id' => 'AB1234567',
            'id_document_type' => IdDocumentType::Passport->value,
        ]);

        // No type supplied — the endpoint tries both canonicalisations.
        $this->actingAs($admin)->getJson('/api/v1/users?national_id='.self::VALID_THAI_ID)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $thai->id);

        $this->actingAs($admin)->getJson('/api/v1/users?national_id=AB1234567')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $passport->id);

        // Lower case / separators canonicalise to the same passport.
        $this->actingAs($admin)->getJson('/api/v1/users?national_id='.urlencode('ab-123 4567'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $passport->id);

        // Narrowing by type explicitly works and does not widen anything.
        $this->actingAs($admin)->getJson('/api/v1/users?national_id=AB1234567&id_document_type=passport')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $passport->id);

        // A passport searched as a Thai ID hashes to the digits only and
        // therefore matches nobody — it must NOT fall through to "all rows".
        $this->actingAs($admin)->getJson('/api/v1/users?national_id=AB1234567&id_document_type=thai_national_id')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Regression guard on the whereIn(): a term that normalizes to nothing
     * must return zero rows, never every agent with a null hash.
     */
    public function test_an_unmatchable_search_term_returns_nothing_rather_than_everything(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        User::factory()->count(3)->agent()->create(['company_id' => $company->id, 'national_id' => null]);

        $this->actingAs($admin)->getJson('/api/v1/users?national_id='.urlencode('---'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
