<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-240 — "which user did what", asked of the audit trail.
 *
 * `actor_user_id` has been written on every row since the table was created
 * and nothing ever read it back: the trail could be browsed by action and by
 * date, but never by person. The human asked for exactly that question, and
 * answering it needed one filter — no new data at all.
 *
 * The BR-6 half is the part worth testing hardest. A Company Admin may only
 * ask about people in their own company, and the refusal has to look like an
 * empty result rather than an error: a 403 for "that actor is in another
 * company" would answer a question nobody outside should be able to ask —
 * whether that user id exists.
 */
class AuditLogActorFilterTest extends TestCase
{
    use RefreshDatabase;

    private Company $thaiLife;

    private Company $genesenn;

    private User $thaiLifeAdmin;

    private User $genesennAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thaiLife = Company::factory()->create();
        $this->genesenn = Company::factory()->create();

        $this->thaiLifeAdmin = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);
        $this->genesennAdmin = User::factory()->companyAdmin()->create(['company_id' => $this->genesenn->id]);

        $this->writeRow($this->thaiLifeAdmin, $this->thaiLife, 'commission_rule.created');
        $this->writeRow($this->thaiLifeAdmin, $this->thaiLife, 'user.role_changed');
        $this->writeRow($this->genesennAdmin, $this->genesenn, 'commission_rule.created');
    }

    private function writeRow(User $actor, Company $company, string $action): void
    {
        AuditLog::create([
            'company_id' => $company->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => User::class,
            'auditable_id' => $actor->id,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => '127.0.0.1',
        ]);
    }

    public function test_a_super_admin_can_ask_what_one_person_did(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)
            ->getJson("/api/v1/audit-logs?actor_user_id={$this->thaiLifeAdmin->id}")
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_without_the_filter_nothing_is_narrowed(): void
    {
        // The filter is additive: leaving it out must keep the whole trail,
        // not default to some "recent actor" the caller never asked for.
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/audit-logs')->assertOk();

        $this->assertCount(3, $response->json('data'));
    }

    public function test_a_company_admin_asking_about_their_own_person_gets_the_rows(): void
    {
        $response = $this->actingAs($this->thaiLifeAdmin)
            ->getJson("/api/v1/audit-logs?actor_user_id={$this->thaiLifeAdmin->id}")
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_a_company_admin_asking_about_another_companys_person_gets_nothing(): void
    {
        /*
         * BR-6, and the shape of the refusal matters as much as the refusal.
         * Empty, not 403: an error would confirm that this user id exists,
         * which is precisely what a Company Admin must not be able to learn
         * about another tenant.
         */
        $response = $this->actingAs($this->thaiLifeAdmin)
            ->getJson("/api/v1/audit-logs?actor_user_id={$this->genesennAdmin->id}")
            ->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    public function test_the_filter_combines_with_the_action_filter_rather_than_replacing_it(): void
    {
        // Two filters that silently override each other are worse than one.
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)
            ->getJson("/api/v1/audit-logs?actor_user_id={$this->thaiLifeAdmin->id}&action=commission_rule")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }
}
