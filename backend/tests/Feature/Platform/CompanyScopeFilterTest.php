<?php

namespace Tests\Feature\Platform;

use App\Enums\CertTierTargetMode;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-209 / ADR-038 — the Super Admin's header company scope, applied
 * server-side by App\Support\CompanyScopeFilter.
 *
 * The security-critical half of this is the third test: the filter must be
 * incapable of WIDENING anyone's view. A Company Admin who hand-writes
 * `?company_id=<other company>` must still get exactly their own rows
 * (TenantScope), not the other company's and not both (BR-6, Section 5
 * rule 5 — the same IDOR guard every other endpoint is held to).
 */
class CompanyScopeFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_without_a_scope_still_sees_every_company(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        Product::factory()->for(Company::factory()->create())->create();
        Product::factory()->for(Company::factory()->create())->create();

        $this->actingAs($superAdmin)
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_super_admin_with_a_scope_sees_only_that_company(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $wanted = Company::factory()->create();
        $other = Company::factory()->create();
        $mine = Product::factory()->for($wanted)->create();
        Product::factory()->for($other)->create();

        $data = $this->actingAs($superAdmin)
            ->getJson("/api/v1/products?company_id={$wanted->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data');

        $this->assertSame($mine->id, $data[0]['id']);
    }

    public function test_a_company_admin_cannot_use_the_scope_to_see_another_company(): void
    {
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $own->id]);
        $ownProduct = Product::factory()->for($own)->create();
        Product::factory()->for($other)->create();

        // Hand-written query string naming somebody else's company.
        $data = $this->actingAs($admin)
            ->getJson("/api/v1/products?company_id={$other->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data');

        $this->assertSame($ownProduct->id, $data[0]['id'], 'the filter widened a Company Admin scope — BR-6 violation');
    }

    /**
     * TASK-209 §5 — a NULL company_id on announcements/reward_items/
     * gamification_rules is a business value ("applies to every company"),
     * not an unscoped row. Scoping to a company must therefore keep showing
     * those, or the admin sees less than the agents in that company do.
     */
    public function test_scoping_keeps_platform_wide_rows_visible(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $wanted = Company::factory()->create();
        $other = Company::factory()->create();

        $this->announcement($wanted->id);
        $this->announcement($other->id);
        $this->announcement(null); // platform-wide

        $this->actingAs($superAdmin)
            ->getJson("/api/v1/announcements?company_id={$wanted->id}")
            ->assertOk()
            // the company's own + the platform-wide one, never the other company's
            ->assertJsonCount(2, 'data');
    }

    /** Same shape AnnouncementVisibilityTest uses — there is no factory for this model. */
    private function announcement(?int $companyId): Announcement
    {
        return Announcement::create([
            'company_id' => $companyId,
            'title' => 'Announcement',
            'content' => 'Body',
            'audience' => 'all_agents',
            'target_cert_tier_id' => null,
            'target_cert_tier_mode' => CertTierTargetMode::Exact,
            'is_pinned' => false,
            'published_at' => now()->subDay(),
            'expires_at' => null,
            'created_by' => null,
        ]);
    }
}
