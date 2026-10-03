<?php

namespace Tests\Feature\Account;

use App\Enums\AccountDeletionRequestStatus;
use App\Enums\IdDocumentType;
use App\Enums\PaymentStatus;
use App\Models\AccountDeletionRequest;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Referral;
use App\Models\SocialAccount;
use App\Models\User;
use App\Policies\AccountDeletionRequestPolicy;
use App\Services\Account\AccountDeletionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MOB-12 (2026-10-02) — in-app account deletion as a request a Company Admin
 * decides (App Store guideline 5.1.1(v); owner decision on the flow).
 *
 * What is pinned here, in the order the flow runs:
 *   * asking re-checks the password, signs the person out everywhere and
 *     blocks login with `deletion_requested`;
 *   * one pending request per person;
 *   * approving anonymises exactly the personal fields, keeps the users row,
 *     and leaves commission_ledger byte-identical (BR-4);
 *   * rejecting lets the person sign in again;
 *   * BR-6: another company's admin can neither see nor decide a request,
 *     and an agent cannot reach the admin endpoints at all;
 *   * the three warning counts are computed from a known fixture.
 */
class AccountDeletionRequestTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private Company $company;

    private User $agent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->agent = User::factory()->agent()->create([
            'company_id' => $this->company->id,
            'password' => Hash::make(self::PASSWORD),
            'phone' => '0812345678',
        ]);
        $this->admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);

        // 2026-10-03 (owner) — an agent who owes nothing is deleted on the
        // spot, so the admin-decided flow these tests pin only exists for an
        // agent with unpaid commission who chose to keep it. The default
        // agent therefore starts owed 100.00 baht; the immediate-deletion
        // tests further down use agents who are not.
        $this->owe($this->agent, self::OWED);
    }

    private const OWED = 10_000;

    /**
     * One unpaid commission row for $agent, through the real factories
     * (client → referral → ledger), so every count below stays honest.
     */
    private function owe(User $agent, int $satang, PaymentStatus $status = PaymentStatus::Pending): CommissionLedger
    {
        // The factories look rows up through TenantScope; a user left signed
        // in by an earlier request would hide another company's client.
        $this->app['auth']->forgetGuards();

        $client = Client::factory()->create(['company_id' => $agent->company_id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::factory()->create(['client_id' => $client->id]);

        return CommissionLedger::factory()->create([
            'referral_id' => $referral->id,
            'amount_satang' => $satang,
            'payment_status' => $status,
            'paid_at' => $status === PaymentStatus::Paid ? now() : null,
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** Token-mode login, exactly as the agent portal / mobile app does it. */
    private function tokenLogin(User $user, string $password = self::PASSWORD): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('X-Auth-Mode', 'token')
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => $password]);
    }

    private function requestDeletion(User $user, string $password = self::PASSWORD, ?string $reason = null, ?string $choice = 'keep'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user)->postJson('/api/v1/me/account-deletion-request', array_filter([
            'password' => $password,
            'reason' => $reason,
            'commission_choice' => $choice,
        ], fn ($v) => $v !== null));
    }

    private function pendingRequestFor(User $user): AccountDeletionRequest
    {
        return AccountDeletionRequest::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('status', AccountDeletionRequestStatus::Pending->value)
            ->firstOrFail();
    }

    // ── asking ───────────────────────────────────────────────────────────

    public function test_an_agent_can_request_deletion_and_is_signed_out_and_blocked_at_login(): void
    {
        $plain = $this->agent->createToken('agent-portal')->plainTextToken;
        $this->agent->createToken('mobile-app');
        $this->agent->forceFill(['remember_token' => 'still-remembered'])->save();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->withHeader('X-Auth-Mode', 'token')
            ->postJson('/api/v1/me/account-deletion-request', [
                'password' => self::PASSWORD,
                'reason' => 'เลิกทำธุรกิจแล้ว',
                'commission_choice' => 'keep',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure(['data' => ['status', 'requested_at']]);

        $request = $this->pendingRequestFor($this->agent);
        $this->assertSame($this->company->id, $request->company_id);
        $this->assertSame('เลิกทำธุรกิจแล้ว', $request->reason);

        // Every credential is gone: both tokens, and the remember-me token.
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $this->agent->id)->count());
        $this->assertNull($this->agent->fresh()->remember_token);

        // The token that made the call no longer works.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->withHeader('X-Auth-Mode', 'token')
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        // And the correct password is refused at the gate with the new code.
        $this->tokenLogin($this->agent)
            ->assertForbidden()
            ->assertJsonPath('error_code', 'deletion_requested')
            ->assertJsonPath('message', 'บัญชีนี้มีคำขอลบบัญชีที่คุณส่งไว้ จึงเข้าสู่ระบบไม่ได้ระหว่างรอบริษัทพิจารณา หากต้องการยกเลิกคำขอ กรุณาติดต่อผู้ดูแลระบบของบริษัทของคุณ');

        $audit = AuditLog::where('action', 'account_deletion.requested')->firstOrFail();
        $this->assertSame($this->agent->id, $audit->auditable_id);
        $this->assertSame($this->agent->id, $audit->actor_user_id);
        $this->assertTrue($audit->new_values['reason_given']);
        $this->assertSame(2, $audit->new_values['api_tokens_revoked']);
        // The agent's own words stay on the request row, not in the trail.
        $this->assertStringNotContainsString('เลิกทำธุรกิจแล้ว', json_encode($audit->new_values, JSON_UNESCAPED_UNICODE));
    }

    public function test_device_tokens_are_removed_when_the_table_exists(): void
    {
        if (! Schema::hasTable('device_tokens')) {
            $this->markTestSkipped('device_tokens is added by the push-notification work.');
        }

        DB::table('device_tokens')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->agent->id,
            'platform' => 'ios',
            'token' => 'apns-token',
            'token_hash' => hash('sha256', 'apns-token'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->requestDeletion($this->agent)->assertStatus(202);

        $this->assertSame(0, DB::table('device_tokens')->where('user_id', $this->agent->id)->count());
    }

    public function test_a_wrong_password_is_422_in_thai_and_changes_nothing(): void
    {
        $this->agent->createToken('agent-portal');

        $this->requestDeletion($this->agent, 'not-the-password')
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'รหัสผ่านไม่ถูกต้อง');

        $this->assertSame(0, AccountDeletionRequest::withoutGlobalScopes()->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $this->agent->id)->count());
        $this->tokenLogin($this->agent)->assertOk();
    }

    public function test_wrong_passwords_are_throttled_like_login(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->requestDeletion($this->agent, 'wrong-'.$i)->assertStatus(422);
        }

        // The sixth is refused before the password is even compared — the
        // right one included.
        $this->requestDeletion($this->agent)->assertStatus(429);
        $this->assertSame(0, AccountDeletionRequest::withoutGlobalScopes()->count());
    }

    public function test_a_second_request_while_one_is_pending_is_409(): void
    {
        $this->requestDeletion($this->agent)->assertStatus(202);
        $this->requestDeletion($this->agent)->assertStatus(409);

        $this->assertSame(1, AccountDeletionRequest::withoutGlobalScopes()->where('user_id', $this->agent->id)->count());
    }

    public function test_the_database_itself_refuses_a_second_pending_row(): void
    {
        $this->requestDeletion($this->agent)->assertStatus(202);

        $this->expectException(QueryException::class);

        AccountDeletionRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->agent->id,
            'status' => AccountDeletionRequestStatus::Pending,
            'requested_at' => now(),
        ]);
    }

    public function test_admins_cannot_request_their_own_deletion(): void
    {
        $this->requestDeletion($this->admin)
            ->assertForbidden()
            ->assertJsonPath('message', AccountDeletionRequestPolicy::ONLY_AGENTS_MESSAGE);

        $this->requestDeletion(User::factory()->superAdmin()->create(['password' => Hash::make(self::PASSWORD)]))
            ->assertForbidden();

        $this->assertSame(0, AccountDeletionRequest::withoutGlobalScopes()->count());
    }

    // ── approving ────────────────────────────────────────────────────────

    public function test_approving_anonymises_exactly_the_personal_fields_and_leaves_the_ledger_untouched(): void
    {
        Storage::fake('public');
        $avatar = UploadedFile::fake()->image('a.jpg')->store("avatars/{$this->company->id}", 'public');
        $background = UploadedFile::fake()->image('b.jpg')->store("backgrounds/{$this->company->id}", 'public');

        $this->agent->forceFill([
            'bank_name' => 'กสิกรไทย',
            'bank_account_number' => '1234567890',
            'bank_account_holder_name' => 'สมชาย ใจดี',
            'national_id' => '1101700203451',
            'id_document_type' => IdDocumentType::ThaiNationalId,
            'avatar_path' => $avatar,
            'background_type' => 'image',
            'background_image_path' => $background,
        ])->save();
        SocialAccount::create(['user_id' => $this->agent->id, 'provider' => 'line', 'provider_user_id' => 'U123']);

        $client = Client::factory()->create(['company_id' => $this->company->id, 'referring_agent_id' => $this->agent->id]);
        $referral = Referral::factory()->create(['client_id' => $client->id]);
        CommissionLedger::factory()->create(['referral_id' => $referral->id, 'amount_satang' => 12_345]);
        CommissionLedger::factory()->create([
            'referral_id' => $referral->id,
            'amount_satang' => 6_789,
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        $ledgerBefore = DB::table('commission_ledger')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $oldEmail = $this->agent->email;
        $oldName = $this->agent->name;
        $this->requestDeletion($this->agent)->assertStatus(202);
        $request = $this->pendingRequestFor($this->agent);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)
            ->postJson("/api/v1/account-deletion-requests/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.user.name', 'บัญชีที่ถูกลบ #'.$this->agent->id)
            ->assertJsonPath('data.user.email', 'deleted-'.$this->agent->id.'@deleted.invalid')
            ->assertJsonPath('data.user.phone', null);

        $user = User::withTrashed()->withoutGlobalScopes()->findOrFail($this->agent->id);

        // The row survives (FK target for BR-4) and is switched off the same
        // way a deactivated account is.
        $this->assertTrue($user->trashed());
        $this->assertSame('บัญชีที่ถูกลบ', $user->first_name);
        $this->assertSame('#'.$this->agent->id, $user->last_name);
        $this->assertSame('บัญชีที่ถูกลบ #'.$this->agent->id, $user->name);
        $this->assertSame('deleted-'.$this->agent->id.'@deleted.invalid', $user->email);
        $this->assertFalse(Hash::check(self::PASSWORD, $user->password));
        $this->assertFalse($user->email_notifications_enabled);
        foreach (AccountDeletionService::CLEARED_FIELDS as $field) {
            $this->assertNull($user->getAttribute($field), "{$field} should be cleared");
        }
        // What is NOT personal stays exactly as it was.
        $this->assertSame($this->company->id, $user->company_id);
        $this->assertSame($this->agent->role, $user->role);

        Storage::disk('public')->assertMissing($avatar);
        Storage::disk('public')->assertMissing($background);
        $this->assertSame(0, SocialAccount::where('user_id', $this->agent->id)->count());

        // BR-4 — byte-identical.
        $this->assertSame($ledgerBefore, DB::table('commission_ledger')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        // Clients belong to the company; reassigning them is the admin's job.
        $this->assertSame($this->agent->id, $client->fresh()->referring_agent_id);

        $audit = AuditLog::where('action', 'account_deletion.approved')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->actor_user_id);
        $this->assertEqualsCanonicalizing(
            ['phone', 'bank_name', 'bank_account_number', 'bank_account_holder_name', 'national_id', 'national_id_hash', 'id_document_type', 'avatar_path', 'background_type', 'background_image_path'],
            $audit->new_values['fields_cleared'],
        );
        $this->assertSame(2, $audit->new_values['profile_files_deleted']);
        $serialised = json_encode([$audit->old_values, $audit->new_values], JSON_UNESCAPED_UNICODE);
        foreach ([$oldEmail, $oldName, '0812345678', '1234567890', '1101700203451', 'สมชาย ใจดี'] as $personal) {
            $this->assertStringNotContainsString($personal, $serialised);
        }

        // Permanently out: the old credentials no longer identify anybody.
        $this->withHeader('X-Auth-Mode', 'token')
            ->postJson('/api/v1/login', ['email' => $oldEmail, 'password' => self::PASSWORD])
            ->assertStatus(422);
    }

    public function test_approving_or_rejecting_a_decided_request_is_409(): void
    {
        $this->requestDeletion($this->agent)->assertStatus(202);
        $request = $this->pendingRequestFor($this->agent);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->postJson("/api/v1/account-deletion-requests/{$request->id}/approve")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/account-deletion-requests/{$request->id}/approve")->assertStatus(409);
        $this->actingAs($this->admin)->postJson("/api/v1/account-deletion-requests/{$request->id}/reject")->assertStatus(409);

        $this->assertSame(1, AuditLog::where('action', 'account_deletion.approved')->count());
    }

    public function test_an_anonymised_account_cannot_be_restored_from_the_roster(): void
    {
        $this->requestDeletion($this->agent)->assertStatus(202);
        $request = $this->pendingRequestFor($this->agent);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->postJson("/api/v1/account-deletion-requests/{$request->id}/approve")->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/users/{$this->agent->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('errors.user.0', 'บัญชีนี้ถูกลบตามคำขอของเจ้าของบัญชีแล้ว จึงกู้คืนไม่ได้');

        $this->assertTrue(User::withTrashed()->withoutGlobalScopes()->findOrFail($this->agent->id)->trashed());
    }

    // ── rejecting ────────────────────────────────────────────────────────

    public function test_rejecting_records_the_note_and_lets_the_agent_sign_in_again(): void
    {
        $this->requestDeletion($this->agent)->assertStatus(202);
        $request = $this->pendingRequestFor($this->agent);
        $this->tokenLogin($this->agent)->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)
            ->postJson("/api/v1/account-deletion-requests/{$request->id}/reject", ['note' => 'ยังมียอดค้างจ่าย ติดต่อกลับแล้ว'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.decision_note', 'ยังมียอดค้างจ่าย ติดต่อกลับแล้ว')
            ->assertJsonPath('data.decided_by.id', $this->admin->id);

        $this->tokenLogin($this->agent)->assertOk()->assertJsonStructure(['token']);

        $audit = AuditLog::where('action', 'account_deletion.rejected')->firstOrFail();
        $this->assertTrue($audit->new_values['login_unblocked']);
        $this->assertFalse($this->agent->fresh()->trashed());

        // And they may ask again later.
        $this->requestDeletion($this->agent)->assertStatus(202);
    }

    public function test_a_reject_note_longer_than_500_is_422(): void
    {
        $this->requestDeletion($this->agent)->assertStatus(202);
        $request = $this->pendingRequestFor($this->agent);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)
            ->postJson("/api/v1/account-deletion-requests/{$request->id}/reject", ['note' => str_repeat('ก', 501)])
            ->assertStatus(422);
    }

    // ── BR-6 / authorization ─────────────────────────────────────────────

    public function test_another_companys_admin_can_neither_see_nor_decide_the_request(): void
    {
        $this->requestDeletion($this->agent)->assertStatus(202);
        $request = $this->pendingRequestFor($this->agent);

        $otherCompany = Company::factory()->create();
        $otherAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id, 'password' => Hash::make(self::PASSWORD)]);
        $this->owe($otherAgent, self::OWED);
        $this->requestDeletion($otherAgent)->assertStatus(202);
        $otherAdmin = User::factory()->companyAdmin()->create(['company_id' => $otherCompany->id]);

        $this->app['auth']->forgetGuards();
        $ids = collect($this->actingAs($otherAdmin)->getJson('/api/v1/account-deletion-requests')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($request->id, $ids);
        $this->assertCount(1, $ids);

        $this->actingAs($otherAdmin)->postJson("/api/v1/account-deletion-requests/{$request->id}/approve")->assertNotFound();
        $this->actingAs($otherAdmin)->postJson("/api/v1/account-deletion-requests/{$request->id}/reject")->assertNotFound();

        $this->assertTrue($request->fresh()->isPending());
        $this->assertNull(User::withoutGlobalScopes()->findOrFail($this->agent->id)->deleted_at);
    }

    public function test_an_agent_cannot_use_the_admin_endpoints(): void
    {
        $colleague = User::factory()->agent()->create(['company_id' => $this->company->id, 'password' => Hash::make(self::PASSWORD)]);
        $this->owe($colleague, self::OWED);
        $this->requestDeletion($colleague)->assertStatus(202);
        $request = $this->pendingRequestFor($colleague);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->agent)->getJson('/api/v1/account-deletion-requests')->assertForbidden();
        $this->actingAs($this->agent)->postJson("/api/v1/account-deletion-requests/{$request->id}/approve")->assertForbidden();
        $this->actingAs($this->agent)->postJson("/api/v1/account-deletion-requests/{$request->id}/reject")->assertForbidden();

        $this->assertTrue($request->fresh()->isPending());
    }

    public function test_a_super_admin_sees_every_company_and_can_narrow_to_one(): void
    {
        $this->requestDeletion($this->agent)->assertStatus(202);
        $otherCompany = Company::factory()->create();
        $otherAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id, 'password' => Hash::make(self::PASSWORD)]);
        $this->owe($otherAgent, self::OWED);
        $this->requestDeletion($otherAgent)->assertStatus(202);

        $super = User::factory()->superAdmin()->create();
        $this->app['auth']->forgetGuards();

        $this->actingAs($super)->getJson('/api/v1/account-deletion-requests')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($super)
            ->getJson("/api/v1/account-deletion-requests?company_id={$otherCompany->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $otherAgent->id)
            ->assertJsonPath('data.0.company.id', $otherCompany->id);
    }

    // ── the warning counts ───────────────────────────────────────────────

    public function test_the_list_carries_the_three_warning_counts_and_filters_by_status(): void
    {
        // Downline: two active direct recruits, one deactivated (not counted),
        // one grand-recruit (not direct, not counted).
        $recruitA = User::factory()->agent()->create(['company_id' => $this->company->id, 'manager_id' => $this->agent->id]);
        User::factory()->agent()->create(['company_id' => $this->company->id, 'manager_id' => $this->agent->id]);
        User::factory()->agent()->create(['company_id' => $this->company->id, 'manager_id' => $this->agent->id])->delete();
        User::factory()->agent()->create(['company_id' => $this->company->id, 'manager_id' => $recruitA->id]);

        // Clients: two live here plus the one behind setUp()'s owed row, one
        // soft-deleted (not counted).
        $client = Client::factory()->create(['company_id' => $this->company->id, 'referring_agent_id' => $this->agent->id]);
        Client::factory()->create(['company_id' => $this->company->id, 'referring_agent_id' => $this->agent->id]);
        Client::factory()->create(['company_id' => $this->company->id, 'referring_agent_id' => $this->agent->id])->delete();

        // Commission: setUp()'s 10,000 + 10,000 + 5,000 pending, 7,000 paid →
        // 25,000 satang owed.
        $referral = Referral::factory()->create(['client_id' => $client->id]);
        CommissionLedger::factory()->create(['referral_id' => $referral->id, 'amount_satang' => 10_000]);
        CommissionLedger::factory()->create(['referral_id' => $referral->id, 'amount_satang' => 5_000]);
        CommissionLedger::factory()->create([
            'referral_id' => $referral->id,
            'amount_satang' => 7_000,
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $this->requestDeletion($this->agent, reason: 'ย้ายไปต่างประเทศ')->assertStatus(202);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)
            ->getJson('/api/v1/account-deletion-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.reason', 'ย้ายไปต่างประเทศ')
            ->assertJsonPath('data.0.user.id', $this->agent->id)
            ->assertJsonPath('data.0.user.email', $this->agent->email)
            ->assertJsonPath('data.0.user.phone', '0812345678')
            ->assertJsonPath('data.0.pending_commission_satang', 25_000)
            ->assertJsonPath('data.0.downline_count', 2)
            ->assertJsonPath('data.0.client_count', 3)
            ->assertJsonPath('data.0.resolution', null)
            ->assertJsonPath('data.0.forfeited_commission_satang', null);

        // Informational only: approval is NOT blocked by any of them.
        $request = $this->pendingRequestFor($this->agent);
        $this->actingAs($this->admin)->postJson("/api/v1/account-deletion-requests/{$request->id}/approve")->assertOk();

        $this->actingAs($this->admin)->getJson('/api/v1/account-deletion-requests')->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)
            ->getJson('/api/v1/account-deletion-requests?status=approved')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.resolution', 'admin')
            ->assertJsonPath('data.0.pending_commission_satang', 25_000);
        $this->actingAs($this->admin)->getJson('/api/v1/account-deletion-requests?status=bogus')->assertStatus(422);
    }
}
