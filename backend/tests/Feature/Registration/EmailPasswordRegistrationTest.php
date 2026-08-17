<?php

namespace Tests\Feature\Registration;

use App\Enums\AgentApprovalStatus;
use App\Events\AgentReadyForApproval;
use App\Models\Company;
use App\Models\CompanyInviteCode;
use App\Models\User;
use App\Notifications\VerifyRegistrationEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

// TASK-018 — public self-registration (email/password) + email
// verification. Rate limiting is exercised directly (no mocking) since
// it's a real route-level `throttle` middleware, same as any other
// Laravel app's default behavior.
class EmailPasswordRegistrationTest extends TestCase
{
    use RefreshDatabase;

    // TASK-122 — a real Thai national ID (mod-11 checksum verified), added
    // here because an identity document is now MANDATORY on this path too,
    // not just on the recruit link. IdDocumentRegistrationTest owns the
    // behaviour; this constant exists so every pre-existing test in this
    // file keeps exercising what it was written to exercise instead of
    // failing on the new field.
    private const VALID_THAI_ID = '1101700230708';

    private function validRegistrationPayload(string $inviteCode, array $overrides = []): array
    {
        return array_merge([
            'invite_code' => $inviteCode,
            'first_name' => 'Somsri',
            'last_name' => 'Testagent',
            'email' => 'somsri@example.com',
            'phone' => '0812345678',
            'id_document_type' => 'thai_national_id',
            'national_id' => self::VALID_THAI_ID,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides);
    }

    public function test_a_valid_invite_code_resolves_to_its_company_name(): void
    {
        $company = Company::factory()->create(['name' => 'Thai Life']);
        $inviteCode = CompanyInviteCode::factory()->create(['company_id' => $company->id]);

        $this->postJson('/api/v1/register/resolve-invite-code', ['invite_code' => $inviteCode->code])
            ->assertOk()
            ->assertJsonPath('company_name', 'Thai Life');
    }

    public function test_an_unknown_invite_code_is_rejected_generically(): void
    {
        $this->postJson('/api/v1/register/resolve-invite-code', ['invite_code' => 'NOSUCHCODE'])
            ->assertNotFound();
    }

    public function test_an_expired_invite_code_is_rejected_the_same_way_as_unknown(): void
    {
        $company = Company::factory()->create();
        $inviteCode = CompanyInviteCode::factory()->expired()->create(['company_id' => $company->id]);

        $response = $this->postJson('/api/v1/register/resolve-invite-code', ['invite_code' => $inviteCode->code]);

        $response->assertNotFound();
        // Same generic message as the "unknown code" case — never leaks
        // that this specific code once existed but expired.
        $this->assertSame(
            $response->json('message'),
            $this->postJson('/api/v1/register/resolve-invite-code', ['invite_code' => 'totally-made-up'])->json('message'),
        );
    }

    public function test_registration_creates_a_pending_unverified_agent_in_the_correct_company(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $inviteCode = CompanyInviteCode::factory()->create(['company_id' => $company->id]);

        $this->postJson('/api/v1/register', $this->validRegistrationPayload($inviteCode->code))
            ->assertCreated();

        $user = User::withoutGlobalScopes()->where('email', 'somsri@example.com')->firstOrFail();

        $this->assertSame($company->id, $user->company_id);
        $this->assertSame(AgentApprovalStatus::Pending, $user->agent_approval_status);
        $this->assertNull($user->email_verified_at);
        $this->assertSame($inviteCode->id, $user->registered_via_invite_code_id);

        Notification::assertSentTo($user, VerifyRegistrationEmailNotification::class);
    }

    public function test_registration_never_accepts_a_company_id_directly(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $inviteCode = CompanyInviteCode::factory()->create(['company_id' => $company->id]);

        $this->postJson('/api/v1/register', $this->validRegistrationPayload($inviteCode->code, [
            'company_id' => $otherCompany->id, // never accepted — resolved server-side only
        ]))->assertCreated();

        $user = User::withoutGlobalScopes()->where('email', 'somsri@example.com')->firstOrFail();
        $this->assertSame($company->id, $user->company_id);
    }

    public function test_registration_with_an_invalid_invite_code_is_rejected(): void
    {
        $this->postJson('/api/v1/register', $this->validRegistrationPayload('not-a-real-code'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invite_code');
    }

    public function test_registration_with_an_already_used_email_is_rejected(): void
    {
        Notification::fake();
        $company = Company::factory()->create();
        $inviteCode = CompanyInviteCode::factory()->create(['company_id' => $company->id]);
        User::factory()->create(['email' => 'somsri@example.com']);

        $this->postJson('/api/v1/register', $this->validRegistrationPayload($inviteCode->code))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_verifying_the_email_marks_it_verified_and_fires_the_approval_event(): void
    {
        Notification::fake();
        Event::fake([AgentReadyForApproval::class]);

        $company = Company::factory()->create();
        $inviteCode = CompanyInviteCode::factory()->create(['company_id' => $company->id]);
        $this->postJson('/api/v1/register', $this->validRegistrationPayload($inviteCode->code))->assertCreated();

        $user = User::withoutGlobalScopes()->where('email', 'somsri@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);

        $signedUrl = URL::temporarySignedRoute('registration.verify-email', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);
        $path = parse_url($signedUrl, PHP_URL_PATH).'?'.parse_url($signedUrl, PHP_URL_QUERY);

        $this->getJson($path)->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatched(AgentReadyForApproval::class, fn ($event) => $event->user->is($user));
    }

    public function test_verify_email_rejects_a_tampered_hash(): void
    {
        Notification::fake();
        $company = Company::factory()->create();
        $inviteCode = CompanyInviteCode::factory()->create(['company_id' => $company->id]);
        $this->postJson('/api/v1/register', $this->validRegistrationPayload($inviteCode->code))->assertCreated();
        $user = User::withoutGlobalScopes()->where('email', 'somsri@example.com')->firstOrFail();

        $signedUrl = URL::temporarySignedRoute('registration.verify-email', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => 'wrong-hash-value',
        ]);
        $path = parse_url($signedUrl, PHP_URL_PATH).'?'.parse_url($signedUrl, PHP_URL_QUERY);

        $this->getJson($path)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verify_email_rejects_an_unsigned_url(): void
    {
        $company = Company::factory()->create();
        $inviteCode = CompanyInviteCode::factory()->create(['company_id' => $company->id]);
        Notification::fake();
        $this->postJson('/api/v1/register', $this->validRegistrationPayload($inviteCode->code))->assertCreated();
        $user = User::withoutGlobalScopes()->where('email', 'somsri@example.com')->firstOrFail();

        $this->getJson("/api/v1/register/verify-email/{$user->id}/".sha1($user->email))
            ->assertForbidden();
    }

    public function test_resolve_invite_code_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/register/resolve-invite-code', ['invite_code' => 'x'])->assertNotFound();
        }

        $this->postJson('/api/v1/register/resolve-invite-code', ['invite_code' => 'x'])
            ->assertStatus(429);
    }
}
