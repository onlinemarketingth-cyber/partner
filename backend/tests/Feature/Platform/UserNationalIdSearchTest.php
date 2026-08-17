<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// TASK-059/060 — Thai national ID for agents, same PDPA-sensitive
// pattern as ClientNationalIdSearchTest (TASK-049): encrypted at rest,
// mirrored by a deterministic blind-index hash for EXACT search, masked
// to non-privileged viewers, validated as a real Thai 13-digit ID. Plus
// the free-text /users search (name/phone/email LIKE) added alongside it.
class UserNationalIdSearchTest extends TestCase
{
    use RefreshDatabase;

    // Valid Thai national ID (checksum verified): first 12 digits
    // 110170023070 -> check digit 8. Same constant ClientNationalIdSearchTest uses.
    private const VALID_ID = '1101700230708';

    public function test_national_id_is_stored_encrypted_with_a_blind_index_hash(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $target = User::factory()->agent()->create(['company_id' => $company->id]);

        // TASK-122 — `id_document_type` now travels with the number: it is
        // `required_with:national_id` on the Admin path, and it is what
        // decides which canonicalisation the blind index below is built
        // from. Adding it here keeps this test testing what it was written
        // to test (encryption + hash lockstep), not the new rule.
        $this->actingAs($admin)->putJson("/api/v1/users/{$target->id}", [
            'id_document_type' => 'thai_national_id',
            'national_id' => self::VALID_ID,
        ])->assertOk();

        $target->refresh();
        // Decrypted value round-trips.
        $this->assertSame(self::VALID_ID, $target->national_id);
        // Hash is the deterministic HMAC, not the plaintext.
        $this->assertSame(User::hashNationalId(self::VALID_ID), $target->national_id_hash);
        // The raw DB column is ciphertext, never the plaintext number.
        $raw = DB::table('users')->where('id', $target->id)->value('national_id');
        $this->assertNotSame(self::VALID_ID, $raw);
    }

    public function test_invalid_thai_national_id_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $target = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$target->id}", [
            'id_document_type' => 'thai_national_id', // TASK-122 — the type is what selects the checksum rule.
            'national_id' => '1101700230700', // wrong check digit
        ])->assertStatus(422)->assertJsonValidationErrors('national_id');
    }

    public function test_full_national_id_shows_only_to_privileged_viewers(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $target = User::factory()->agent()->create(['company_id' => $company->id, 'national_id' => self::VALID_ID]);

        // Company Admin managing this agent sees the full number + mask
        // (same reveal gate UserResource already applies to
        // bank_account_number, TASK-047).
        $this->actingAs($admin)->getJson("/api/v1/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.national_id', self::VALID_ID)
            ->assertJsonPath('data.national_id_masked', '*********0708');
    }

    public function test_a_cross_company_admin_only_sees_the_masked_national_id(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $otherAdmin = User::factory()->companyAdmin()->create(['company_id' => $otherCompany->id]);
        $target = User::factory()->agent()->create(['company_id' => $ownCompany->id, 'national_id' => self::VALID_ID]);

        // BR-6 — cross-company access is rejected outright (TenantScope),
        // never even reaching the masking layer.
        $this->actingAs($otherAdmin)->getJson("/api/v1/users/{$target->id}")->assertNotFound();
    }

    public function test_search_by_national_id_is_exact_match_only(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $match = User::factory()->agent()->create(['company_id' => $company->id, 'national_id' => self::VALID_ID]);
        User::factory()->agent()->create(['company_id' => $company->id, 'national_id' => null]);

        // Full number -> one hit.
        $this->actingAs($admin)->getJson('/api/v1/users?national_id='.self::VALID_ID)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);

        // Partial number -> no hit (encrypted column, exact-only blind index).
        $this->actingAs($admin)->getJson('/api/v1/users?national_id=11017')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Non-digit garbage -> no hit (must NOT fall through to whereNull
        // and return the national-ID-less agent).
        $this->actingAs($admin)->getJson('/api/v1/users?national_id=abc')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_free_text_search_matches_name_phone_email(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $target = User::factory()->agent()->create([
            'company_id' => $company->id,
            'first_name' => 'สมชาย',
            'last_name' => 'ใจดี',
            'phone' => '0812223333',
            'email' => 'somchai@example.test',
        ]);
        User::factory()->agent()->create([
            'company_id' => $company->id,
            'first_name' => 'อื่นๆ',
            'last_name' => 'คนอื่น',
            'phone' => '0999999999',
            'email' => 'other@example.test',
        ]);

        foreach (['สมชาย', '2223333', 'somchai@example'] as $term) {
            $this->actingAs($admin)->getJson('/api/v1/users?q='.urlencode($term))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $target->id);
        }
    }

    public function test_search_respects_tenant_isolation(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);
        // Same national ID in another company -> must NOT be found by A.
        User::factory()->agent()->create(['company_id' => $companyB->id, 'national_id' => self::VALID_ID]);

        $this->actingAs($adminA)->getJson('/api/v1/users?national_id='.self::VALID_ID)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_national_id_change_writes_an_audit_log_entry(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $target = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$target->id}", [
            'id_document_type' => 'thai_national_id', // TASK-122 — required_with:national_id.
            'national_id' => self::VALID_ID,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id,
            'actor_user_id' => $admin->id,
            'action' => 'user.national_id_updated',
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
        ]);
    }
}
