<?php

namespace Tests\Feature\Engagement;

use App\Enums\CertTierTargetMode;
use App\Enums\CommissionRateType;
use App\Enums\PromotionPayoutTiming;
use App\Enums\PromotionStatus;
use App\Enums\PromotionTargetType;
use App\Models\AgentPromotion;
use App\Models\Announcement;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-156 — A DETAIL ROUTE MUST APPLY ITS OWN LIST'S GATE.
 *
 * `AnnouncementController::index()` has always filtered on the publication
 * window and the audience/cert-tier target. `show()` filtered on nothing, and
 * `AnnouncementPolicy::view()` returns true for anyone in the company — so an
 * Agent who knew an id could read a draft, a post scheduled for next month, an
 * expired one, or one addressed to a tier they had not earned.
 *
 * `AgentPromotionController` had the same shape: `index()` filters on
 * `isCurrentlyActive() && appliesToAgent()`; the Policy checks only the second.
 *
 * "The list hides it" is not mitigation when ids are sequential.
 *
 * Construction below deliberately mirrors CertTierTargetModeTest, including its
 * warning: **CertTierFactory defaults sort_order to 0 for every tier**, so the
 * tiers are made with CertTier::create() and explicit, distinct sort_orders or
 * the `and_above` comparison would be meaningless.
 */
class AnnouncementVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $agent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->agent = User::factory()->agent()->create(['company_id' => $this->company->id]);
        $this->admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);
    }

    /** @return array{0: CertTier, 1: CertTier} [basic, high] */
    private function makeTiers(): array
    {
        return [
            CertTier::create(['key' => 'basic', 'name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]),
            CertTier::create(['key' => 'high', 'name' => 'High', 'sort_order' => 3, 'is_mandatory' => false]),
        ];
    }

    private function passTier(CertTier $tier): void
    {
        UserCertification::create([
            'company_id' => $this->company->id,
            'user_id' => $this->agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);
    }

    private function announcement(array $attrs = []): Announcement
    {
        return Announcement::create(array_merge([
            'company_id' => $this->company->id,
            'title' => 'Announcement',
            'content' => 'Body',
            'audience' => 'all_agents',
            'target_cert_tier_id' => null,
            'target_cert_tier_mode' => CertTierTargetMode::Exact,
            'is_pinned' => false,
            'published_at' => now()->subDay(),
            'expires_at' => null,
            'created_by' => null,
        ], $attrs));
    }

    // ── the publication window ──────────────────────────────────────

    public function test_an_agent_cannot_read_a_future_dated_announcement_by_id(): void
    {
        $future = $this->announcement(['published_at' => now()->addMonth()]);

        $this->actingAs($this->agent)
            ->getJson("/api/v1/announcements/{$future->id}")
            ->assertNotFound();
    }

    public function test_an_agent_cannot_read_an_expired_announcement_by_id(): void
    {
        $expired = $this->announcement([
            'published_at' => now()->subMonths(3),
            'expires_at' => now()->subMonth(),
        ]);

        $this->actingAs($this->agent)
            ->getJson("/api/v1/announcements/{$expired->id}")
            ->assertNotFound();
    }

    public function test_an_agent_can_still_read_a_live_announcement(): void
    {
        // The gate must not be so wide that it breaks the normal path — the
        // Agent Portal opens announcements by id from the home feed.
        $live = $this->announcement();

        $this->actingAs($this->agent)
            ->getJson("/api/v1/announcements/{$live->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $live->id);
    }

    public function test_an_admin_still_reads_everything_because_they_manage_the_queue(): void
    {
        $future = $this->announcement(['published_at' => now()->addMonth()]);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/announcements/{$future->id}")
            ->assertOk();
    }

    // ── the audience gate ───────────────────────────────────────────

    public function test_an_agent_cannot_read_an_announcement_aimed_at_a_tier_they_have_not_earned(): void
    {
        [$basic, $high] = $this->makeTiers();
        $this->passTier($basic);

        $forHighTier = $this->announcement([
            'audience' => 'cert_tier',
            'target_cert_tier_id' => $high->id,
            'target_cert_tier_mode' => CertTierTargetMode::Exact,
        ]);

        $this->actingAs($this->agent)
            ->getJson("/api/v1/announcements/{$forHighTier->id}")
            ->assertNotFound();
    }

    public function test_and_above_targeting_still_reaches_a_higher_tier_agent_on_the_detail_route(): void
    {
        // The `and_above` branch is the half most likely to be broken by a PHP
        // re-implementation, which is why show() re-runs the scope's SQL rather
        // than duplicating the sort_order comparison.
        [$basic, $high] = $this->makeTiers();
        $this->passTier($high);

        $forBasicAndAbove = $this->announcement([
            'audience' => 'cert_tier',
            'target_cert_tier_id' => $basic->id,
            'target_cert_tier_mode' => CertTierTargetMode::AndAbove,
        ]);

        $this->actingAs($this->agent)
            ->getJson("/api/v1/announcements/{$forBasicAndAbove->id}")
            ->assertOk();
    }

    public function test_the_list_and_the_detail_route_agree(): void
    {
        // The invariant this whole task is about: anything show() serves an
        // Agent must be something index() would have listed for them.
        // Held as explicit ids rather than re-queried: TenantScope resolves
        // against the authenticated user, and a bare Announcement::pluck() in a
        // test body runs outside any request.
        $all = [
            $this->announcement()->id,
            $this->announcement(['published_at' => now()->addMonth()])->id,
            $this->announcement(['published_at' => now()->subMonths(3), 'expires_at' => now()->subMonth()])->id,
        ];

        $listed = collect(
            $this->actingAs($this->agent)->getJson('/api/v1/announcements')->assertOk()->json('data')
        )->pluck('id');

        foreach ($all as $id) {
            $status = $this->actingAs($this->agent)->getJson("/api/v1/announcements/{$id}")->status();

            $this->assertSame(
                $listed->contains($id) ? 200 : 404,
                $status,
                "Announcement {$id} is listed and readable inconsistently.",
            );
        }
    }

    // ── agent promotions, same shape ────────────────────────────────

    public function test_an_agent_cannot_read_a_promotion_that_has_not_started(): void
    {
        $scheduled = AgentPromotion::create([
            'company_id' => $this->company->id,
            'product_id' => null,
            'name' => 'Next quarter kicker',
            'description' => null,
            'target_type' => PromotionTargetType::AllAgents,
            'target_cert_tier_id' => null,
            'target_cert_tier_mode' => CertTierTargetMode::Exact,
            'bonus_type' => CommissionRateType::FixedSatang,
            'bonus_value' => 500000,
            'payout_timing' => PromotionPayoutTiming::Immediate,
            'status' => PromotionStatus::Active,
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonths(2),
            'created_by' => null,
        ]);

        // Before TASK-156 this returned 200 with the bonus amount in the body.
        $this->actingAs($this->agent)
            ->getJson("/api/v1/agent-promotions/{$scheduled->id}")
            ->assertNotFound();
    }
}
