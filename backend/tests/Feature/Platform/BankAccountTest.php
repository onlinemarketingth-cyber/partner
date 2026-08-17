<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// TASK-044 Phase A — bank payout details. Covers both write paths
// (self-service /me/bank-account, Admin /users/{user}).
//
// TASK-047 — human-confirmed reversal of the original masking contract
// below ("แสดงเลยครับ เพราะต้องใช้งาน" — show it directly, needed for
// actual use; a hide/show toggle is explicitly deferred to a future
// system-settings task). UserResource now reveals the REAL number
// whenever the viewer can('view', $target) per UserPolicy — i.e. Company
// Admin managing an agent in their OWN company, or Super Admin managing
// any non-Super-Admin — on top of the pre-existing forOwner() (self)
// case. The tests below (previously asserting masked output for the
// Manage Agents list/show/update endpoints) are updated to assert the
// real number instead, since a Company Admin viewing their own agent is
// exactly the case that's now unmasked.
//
// Section 6 Audit Log rule is UNCHANGED and still fully enforced: every
// bank field write must be logged with the account number MASKED in both
// old_values/new_values, never the plaintext full number — this is a
// separate code path (User::maskBankAccountNumber() called directly by
// UserService/UserProfileService before writing to AuditLog), untouched
// by the Resource-layer reveal change above.
class BankAccountTest extends TestCase
{
    use RefreshDatabase;

    // --- Self-service (agent, /me/bank-account) ---

    public function test_agent_can_set_their_own_bank_account_and_the_response_shows_the_full_number(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->putJson('/api/v1/me/bank-account', [
                'bank_name' => 'Bangkok Bank',
                'bank_account_number' => '1234567890',
                'bank_account_holder_name' => 'Somchai Jaidee',
            ])
            ->assertOk()
            ->assertJsonPath('data.bank_name', 'Bangkok Bank')
            // forOwner() — full unmasked number on the caller's own row.
            ->assertJsonPath('data.bank_account_number', '1234567890')
            ->assertJsonPath('data.bank_account_holder_name', 'Somchai Jaidee');

        $this->assertSame('1234567890', $agent->fresh()->bank_account_number);
    }

    public function test_self_service_bank_update_writes_an_audit_log_with_the_number_masked_in_old_and_new_values(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'bank_name' => 'SCB',
            'bank_account_number' => '1111111111',
            'bank_account_holder_name' => 'Old Name',
        ]);

        $this->actingAs($agent)
            ->putJson('/api/v1/me/bank-account', ['bank_account_number' => '2222222222'])
            ->assertOk();

        $log = AuditLog::where('action', 'user.bank_account_updated')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $agent->id)
            ->firstOrFail();

        // Masked (last-4) — 10-digit numbers -> 6 stars + last 4 digits.
        $this->assertSame('******1111', $log->old_values['bank_account_number']);
        $this->assertSame('******2222', $log->new_values['bank_account_number']);

        // Never the plaintext full number anywhere in the logged payload.
        $this->assertStringNotContainsString('1111111111', json_encode($log->old_values));
        $this->assertStringNotContainsString('2222222222', json_encode($log->new_values));

        // Self-service: actor is the same row being edited.
        $this->assertSame($agent->id, $log->actor_user_id);
        $this->assertSame($company->id, $log->company_id);
    }

    public function test_self_service_bank_update_only_touches_fields_actually_sent(): void
    {
        $agent = User::factory()->agent()->create([
            'bank_name' => 'SCB',
            'bank_account_number' => '1111111111',
            'bank_account_holder_name' => 'Original Holder',
        ]);

        $this->actingAs($agent)
            ->putJson('/api/v1/me/bank-account', ['bank_name' => 'Kasikorn'])
            ->assertOk()
            ->assertJsonPath('data.bank_name', 'Kasikorn')
            ->assertJsonPath('data.bank_account_holder_name', 'Original Holder');

        $this->assertSame('1111111111', $agent->fresh()->bank_account_number);
    }

    public function test_bank_account_number_is_encrypted_at_rest(): void
    {
        // Section 6 PDPA — "at-rest encryption for sensitive fields".
        // User's 'encrypted' cast transparently decrypts at the PHP/Eloquent
        // layer, so this reads the RAW column via the query builder
        // (bypassing the cast) to prove the stored ciphertext is not the
        // plaintext number.
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)
            ->putJson('/api/v1/me/bank-account', ['bank_account_number' => '1234567890'])
            ->assertOk();

        $raw = DB::table('users')->where('id', $agent->id)->value('bank_account_number');

        $this->assertNotSame('1234567890', $raw);
        $this->assertStringNotContainsString('1234567890', (string) $raw);
        // Decrypts back correctly through the model's cast.
        $this->assertSame('1234567890', $agent->fresh()->bank_account_number);
    }

    public function test_bank_account_endpoints_require_authentication(): void
    {
        $this->putJson('/api/v1/me/bank-account', ['bank_account_number' => '123'])->assertUnauthorized();
    }

    // --- Admin update path (/users/{user}) ---

    public function test_company_admin_can_update_an_agents_bank_account_in_their_own_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", [
                'bank_name' => 'Kasikorn',
                'bank_account_number' => '9999999999',
                'bank_account_holder_name' => 'Agent Name',
            ])
            ->assertOk()
            ->assertJsonPath('data.bank_name', 'Kasikorn')
            // TASK-047 — Company Admin managing their own agent now sees
            // the REAL number via UserResource's can('view', ...) reveal
            // check, not just a masked confirmation of what was written.
            ->assertJsonPath('data.bank_account_number', '9999999999');

        $this->assertSame('9999999999', $agent->fresh()->bank_account_number);
    }

    public function test_company_admin_cannot_update_bank_account_of_an_agent_in_a_different_company(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $foreignAgent = User::factory()->agent()->create([
            'company_id' => $otherCompany->id,
            'bank_account_number' => '5555555555',
        ]);

        // Matches this codebase's established convention for cross-company
        // user access (UserManagementTest::test_cross_company_user_access_is_404):
        // TenantScope excludes the foreign row from route-model-binding's
        // query entirely, so it 404s before the Policy is even reached.
        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$foreignAgent->id}", ['bank_account_number' => '6666666666'])
            ->assertNotFound();

        $this->assertSame('5555555555', $foreignAgent->fresh()->bank_account_number);
    }

    public function test_admin_bank_update_writes_an_audit_log_with_the_admin_as_actor_not_the_agent(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'bank_name' => 'SCB',
            'bank_account_number' => '1111111111',
            'bank_account_holder_name' => 'Old Name',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", ['bank_account_number' => '7777777777'])
            ->assertOk();

        $log = AuditLog::where('action', 'user.bank_account_updated')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $agent->id)
            ->firstOrFail();

        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertNotSame($agent->id, $log->actor_user_id);
        $this->assertSame('******1111', $log->old_values['bank_account_number']);
        $this->assertSame('******7777', $log->new_values['bank_account_number']);
        $this->assertStringNotContainsString('7777777777', json_encode($log->new_values));
    }

    public function test_admin_update_of_unrelated_fields_does_not_write_a_bank_audit_log(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'first_name' => 'Old']);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", ['first_name' => 'New'])
            ->assertOk();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'user.bank_account_updated',
            'auditable_id' => $agent->id,
        ]);
    }

    // --- TASK-047: reveal on Admin-managing-own-company-agent responses ---

    public function test_manage_agents_list_endpoint_shows_the_real_bank_account_number_to_the_company_admin(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        User::factory()->agent()->create([
            'company_id' => $company->id,
            'bank_account_number' => '1234567890',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/users')->assertOk();

        $numbers = collect($response->json('data'))->pluck('bank_account_number');
        $this->assertTrue($numbers->contains('1234567890'));
        $this->assertFalse($numbers->contains('******7890'));
    }

    public function test_manage_agents_show_endpoint_shows_the_real_bank_account_number_to_the_company_admin(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'bank_account_number' => '1234567890',
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/users/{$agent->id}")
            ->assertOk()
            ->assertJsonPath('data.bank_account_number', '1234567890');
    }

    public function test_manage_agents_show_endpoint_shows_the_real_bank_account_number_to_super_admin(): void
    {
        // UserPolicy::view() — Super Admin -> true for any non-Super-Admin
        // target, regardless of company — same reveal check as Company
        // Admin above, just without the same-company_id restriction.
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'bank_account_number' => '1234567890',
        ]);

        $this->actingAs($superAdmin)
            ->getJson("/api/v1/users/{$agent->id}")
            ->assertOk()
            ->assertJsonPath('data.bank_account_number', '1234567890');
    }

    public function test_agents_own_me_endpoint_shows_the_full_bank_account_number(): void
    {
        $agent = User::factory()->agent()->create(['bank_account_number' => '1234567890']);

        $this->actingAs($agent)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.bank_account_number', '1234567890');
    }

    // --- Regression: multi-byte (Thai) account "number" must not corrupt the audit log ---
    //
    // Real incident (2026-07-23): bank_account_number is free-text
    // (validated only as string|max:255, never numeric-only), so it can
    // legitimately contain Thai text. User::maskBankAccountNumber() used
    // to use strlen()/substr() — BYTE-based, not character-based — which
    // for a multi-byte UTF-8 string can slice through the middle of a
    // character and produce an invalid byte sequence. That corrupted
    // string was then written into AuditLog's JSON-cast old_values/
    // new_values, and json_encode() threw a JsonEncodingException — an
    // uncaught 500 that fired AFTER the underlying update() had already
    // committed, so the write silently succeeded behind a "save failed"
    // response with no audit trail. Fixed by switching the mask helper to
    // mb_strlen()/mb_substr() and wrapping both write paths in
    // DB::transaction() (belt-and-suspenders: even if some other future
    // failure mode reappears, the data write and its audit log entry can
    // never diverge again).
    //
    // 'กขคงจฉชซฌญ' — 10 distinct Thai consonants with NO combining vowel
    // marks (unlike e.g. 'บัญชี', which mixes multi-codepoint clusters),
    // so mb_strlen() is unambiguous: exactly 10 characters, 3 bytes each
    // in UTF-8 (30 bytes total). The last 4 characters ('ชซฌญ') straddle a
    // byte boundary under the old byte-based substr(-4), which is exactly
    // what used to corrupt the UTF-8.
    public function test_self_service_bank_update_with_multibyte_account_number_does_not_500_or_corrupt_the_audit_log(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)
            ->putJson('/api/v1/me/bank-account', ['bank_account_number' => 'กขคงจฉชซฌญ'])
            ->assertOk()
            ->assertJsonPath('data.bank_account_number', 'กขคงจฉชซฌญ');

        $log = AuditLog::where('action', 'user.bank_account_updated')
            ->where('auditable_id', $agent->id)
            ->firstOrFail();

        $this->assertSame('******ชซฌญ', $log->new_values['bank_account_number']);
    }

    public function test_admin_bank_update_with_multibyte_account_number_does_not_500_or_corrupt_the_audit_log(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", ['bank_account_number' => 'กขคงจฉชซฌญ'])
            ->assertOk()
            // TASK-047 — the HTTP response itself is now unmasked (Admin
            // managing their own agent); the audit log entry asserted
            // below is the part that must STAY masked (Section 6).
            ->assertJsonPath('data.bank_account_number', 'กขคงจฉชซฌญ');

        $this->assertSame('กขคงจฉชซฌญ', $agent->fresh()->bank_account_number);

        $log = AuditLog::where('action', 'user.bank_account_updated')
            ->where('auditable_id', $agent->id)
            ->firstOrFail();

        $this->assertSame('******ชซฌญ', $log->new_values['bank_account_number']);
    }

    public function test_a_short_account_number_is_masked_entirely_in_the_audit_log(): void
    {
        // User::maskBankAccountNumber(): <= 4 chars masks every character
        // (no digits left over to reveal). TASK-047 unmasked the HTTP
        // response for an authorized Admin/owner, so the audit log write
        // path (still always masked, Section 6) is the remaining place
        // this behavior is actually observable end-to-end.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", ['bank_account_number' => '123'])
            ->assertOk()
            ->assertJsonPath('data.bank_account_number', '123');

        $log = AuditLog::where('action', 'user.bank_account_updated')
            ->where('auditable_id', $agent->id)
            ->firstOrFail();

        $this->assertSame('***', $log->new_values['bank_account_number']);
    }
}
