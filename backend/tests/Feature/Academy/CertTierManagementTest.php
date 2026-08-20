<?php

namespace Tests\Feature\Academy;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Academy\CertTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * TASK-221 — cert tiers become creatable through the API/UI.
 *
 * The two that matter most are in the last block: a Company Admin must not
 * be able to write to a table that has no company_id and is therefore
 * shared by every tenant, and a tier something still depends on must
 * refuse deletion with a SENTENCE rather than a 500 carrying an SQLSTATE.
 */
class CertTierManagementTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function companyAdmin(): User
    {
        return User::factory()->companyAdmin()->create([
            'company_id' => Company::factory()->create()->id,
        ]);
    }

    private function certifiedAgentFor(CertTier $tier): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);
    }

    // --- reading ------------------------------------------------------

    /**
     * The regression this task also fixes: TASK-209 added a
     * CompanyScopeFilter to a table with no company_id, so any caller
     * passing ?company_id= got a 500. Nothing in the app sends it, which is
     * the only reason it was never seen.
     */
    public function test_the_list_ignores_a_company_id_instead_of_500ing(): void
    {
        CertTier::create(['key' => 'basic', 'name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $company = Company::factory()->create();

        $this->actingAs($this->superAdmin())
            ->getJson("/api/v1/cert-tiers?company_id={$company->id}")
            ->assertOk()
            ->assertJsonPath('data.0.key', 'basic');
    }

    public function test_every_role_can_read_the_list(): void
    {
        CertTier::create(['key' => 'basic', 'name' => 'Basic', 'sort_order' => 1]);
        $agent = User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($agent)->getJson('/api/v1/cert-tiers')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_list_is_ordered_by_sort_order(): void
    {
        CertTier::create(['key' => 'high', 'name' => 'High', 'sort_order' => 3]);
        CertTier::create(['key' => 'basic', 'name' => 'Basic', 'sort_order' => 1]);
        CertTier::create(['key' => 'mid', 'name' => 'Mid', 'sort_order' => 2]);

        $keys = collect($this->actingAs($this->superAdmin())
            ->getJson('/api/v1/cert-tiers')->assertOk()->json('data'))->pluck('key')->all();

        $this->assertSame(['basic', 'mid', 'high'], $keys);
    }

    // --- writing ------------------------------------------------------

    public function test_a_super_admin_can_create_a_tier(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/cert-tiers', [
                'key' => 'basic', 'name' => 'ระดับพื้นฐาน', 'is_mandatory' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'basic')
            ->assertJsonPath('data.name', 'ระดับพื้นฐาน')
            ->assertJsonPath('data.is_mandatory', true)
            // Omitted sort_order must not silently become 0 — two tiers
            // sharing 0 makes every "highest passed tier" query arbitrary.
            ->assertJsonPath('data.sort_order', 1);

        $this->assertDatabaseHas('cert_tiers', ['key' => 'basic']);
    }

    public function test_an_omitted_sort_order_takes_the_next_free_slot(): void
    {
        CertTier::create(['key' => 'a', 'name' => 'A', 'sort_order' => 7]);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/cert-tiers', ['key' => 'b', 'name' => 'B'])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 8);
    }

    public function test_a_key_must_be_unique_and_url_safe(): void
    {
        CertTier::create(['key' => 'basic', 'name' => 'Basic', 'sort_order' => 1]);
        $super = $this->superAdmin();

        $this->actingAs($super)->postJson('/api/v1/cert-tiers', ['key' => 'basic', 'name' => 'Dup'])
            ->assertStatus(422)->assertJsonValidationErrors('key');

        $this->actingAs($super)->postJson('/api/v1/cert-tiers', ['key' => 'Has Space', 'name' => 'Bad'])
            ->assertStatus(422)->assertJsonValidationErrors('key');
    }

    public function test_a_super_admin_can_rename_a_tier(): void
    {
        $tier = CertTier::create(['key' => 'basic', 'name' => 'Basic', 'sort_order' => 1]);

        $this->actingAs($this->superAdmin())
            ->putJson("/api/v1/cert-tiers/{$tier->id}", ['name' => 'พื้นฐาน'])
            ->assertOk()
            ->assertJsonPath('data.name', 'พื้นฐาน');
    }

    public function test_an_unused_tier_can_be_deleted(): void
    {
        $tier = CertTier::create(['key' => 'temp', 'name' => 'Temp', 'sort_order' => 9]);

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/v1/cert-tiers/{$tier->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('cert_tiers', ['id' => $tier->id]);
    }

    // --- the rules that matter ----------------------------------------

    /** cert_tiers has no company_id — one list, shared by every tenant. */
    public function test_a_company_admin_cannot_create_update_or_delete_a_tier(): void
    {
        $tier = CertTier::create(['key' => 'basic', 'name' => 'Basic', 'sort_order' => 1]);
        $admin = $this->companyAdmin();

        $this->actingAs($admin)->postJson('/api/v1/cert-tiers', ['key' => 'x', 'name' => 'X'])->assertForbidden();
        $this->actingAs($admin)->putJson("/api/v1/cert-tiers/{$tier->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/v1/cert-tiers/{$tier->id}")->assertForbidden();

        $this->assertDatabaseHas('cert_tiers', ['id' => $tier->id, 'name' => 'Basic']);
    }

    public function test_an_agent_cannot_write_either(): void
    {
        $agent = User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($agent)->postJson('/api/v1/cert-tiers', ['key' => 'x', 'name' => 'X'])->assertForbidden();
    }

    /**
     * THE delete guard. Eleven tables point here with restrictOnDelete;
     * without this the admin gets a 500 with an SQLSTATE in it instead of
     * being told what is still using the tier.
     */
    public function test_a_tier_in_use_refuses_deletion_with_a_readable_message(): void
    {
        $tier = CertTier::create(['key' => 'basic', 'name' => 'Basic', 'sort_order' => 1]);
        $this->certifiedAgentFor($tier);

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/v1/cert-tiers/{$tier->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('cert_tier');

        $this->assertDatabaseHas('cert_tiers', ['id' => $tier->id]);
    }

    /** The key is a handle code matches on — it must not move under live data. */
    public function test_the_key_cannot_be_changed_once_the_tier_is_in_use(): void
    {
        $tier = CertTier::create(['key' => 'basic', 'name' => 'Basic', 'sort_order' => 1]);
        $this->certifiedAgentFor($tier);

        $this->actingAs($this->superAdmin())
            ->putJson("/api/v1/cert-tiers/{$tier->id}", ['key' => 'renamed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('key');

        // ...but the display name still moves freely.
        $this->actingAs($this->superAdmin())
            ->putJson("/api/v1/cert-tiers/{$tier->id}", ['name' => 'ระดับพื้นฐาน'])
            ->assertOk();

        $this->assertDatabaseHas('cert_tiers', ['id' => $tier->id, 'key' => 'basic', 'name' => 'ระดับพื้นฐาน']);
    }

    /** The Service guards independently of any Gate — a job or command path. */
    public function test_the_service_refuses_to_delete_a_tier_in_use_without_a_gate(): void
    {
        $tier = CertTier::create(['key' => 'basic', 'name' => 'Basic', 'sort_order' => 1]);
        $this->certifiedAgentFor($tier);

        $this->expectException(ValidationException::class);
        app(CertTierService::class)->delete($tier);
    }
}
