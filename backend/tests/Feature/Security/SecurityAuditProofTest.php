<?php

namespace Tests\Feature\Security;

use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Models\Announcement;
use App\Models\Badge;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\Referral;
use App\Models\RewardItem;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SECURITY AUDIT 2026-08-21 — the three holes that were PROVED, then closed.
 *
 * ── WHY THIS FILE EXISTS AS ITS OWN THING ──
 *
 * Each of these was first written the other way round — asserting the
 * BROKEN behaviour — and run green against the code as it stood, because a
 * security finding that has only been reasoned about is a hypothesis. Only
 * once each passed as a working exploit was the fix written and each
 * assertion flipped to the expectation below. Every test here is therefore
 * known to fail against the pre-fix code, which is the only property that
 * makes a regression test worth keeping.
 *
 * They live together rather than being scattered into OrderTest,
 * AnnouncementTest and the rest, because what they have in common is not a
 * feature: it is that the entire existing suite — 1,700 tests — was green
 * while all three were open. Something about the way this codebase tests
 * missed all of them, and whoever goes looking for that pattern should
 * find the three of them side by side.
 *
 * NOT COVERED HERE, and worth naming rather than leaving to be discovered:
 * the double-commission race (audit V2, fixed by locking the referral in
 * CommissionService). SQLite compiles lockForUpdate() to nothing, so
 * nothing this suite can assert would prove or disprove that fix. Do not
 * read the absence of a test there as the absence of a change.
 */
class SecurityAuditProofTest extends TestCase
{
    use RefreshDatabase;

    private function makeReferral(Company $company, User $agent, PipelineStage $stage): Referral
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);
        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 300,
        ]);

        return Referral::create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay(),
            'current_stage' => $stage,
            'meeting_number' => null,
            'submitted_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // V1 — the earner is not the verifier, and there must be a slip.
    // -----------------------------------------------------------------

    public function test_an_agent_cannot_confirm_the_payment_of_their_own_order(): void
    {
        // THE EXPLOIT THIS REPLACES: create a client, submit a referral,
        // walk your own pipeline to the stage before payment, mint the
        // order, confirm it — and hold a BR-4 commission row, which may
        // never be edited or deleted, for a sale nobody paid for. Every
        // step of that was reachable by one agent acting alone.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($agent)
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertForbidden();

        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_nobody_can_confirm_a_payment_with_no_slip_on_file(): void
    {
        // The second half of the same hole, and the half that survives even
        // if someone later re-grants agents the confirm ability: Pending is
        // the state an order is BORN in, so before this the very first
        // thing that could happen to a brand-new order was "confirmed
        // paid", with nothing in the system claiming anyone had paid.
        //
        // Asserted against a Company Admin — the most privileged actor who
        // routinely does this — precisely so it cannot be mistaken for a
        // restatement of the authorization test above.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);

        $order = Order::factory()->create(['referral_id' => $referral->id]);
        $this->assertNull($order->slip_path, 'precondition: no proof of payment exists');

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseCount('commission_ledger', 0);
        $this->assertSame('pending', $order->fresh()->status->value);
    }

    public function test_a_company_admin_still_closes_a_paid_order_normally(): void
    {
        // The two fixes above are only correct if the legitimate path still
        // works. A security change that quietly breaks revenue collection
        // gets reverted by whoever is on call, not fixed.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        // And the commission still lands on the AGENT, never the confirmer.
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'agent_id' => $agent->id,
            'amount_satang' => 26700,
        ]);
        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
    }

    // -----------------------------------------------------------------
    // V4 / V5 — BR-6, the three policies that returned true for everyone.
    // -----------------------------------------------------------------

    public function test_a_company_admin_cannot_read_another_companys_announcement(): void
    {
        $victim = Company::factory()->create();
        $attackerCompany = Company::factory()->create();
        $attacker = User::factory()->companyAdmin()->create(['company_id' => $attackerCompany->id]);

        $secret = Announcement::create([
            'company_id' => $victim->id,
            'title' => 'แผนคอมมิชชั่นใหม่ ไตรมาส 4 (ยังไม่เผยแพร่)',
            'content' => 'ภายในเท่านั้น',
            'audience' => 'all_agents',
            'published_at' => now()->addMonth(),
        ]);

        $this->actingAs($attacker)
            ->getJson("/api/v1/announcements/{$secret->id}")
            ->assertForbidden();
    }

    public function test_an_agent_cannot_read_another_companys_private_badge_or_reward(): void
    {
        $victim = Company::factory()->create();
        $attackerCompany = Company::factory()->create();
        $attacker = User::factory()->agent()->create(['company_id' => $attackerCompany->id]);

        $badge = Badge::factory()->create(['company_id' => $victim->id]);
        $reward = RewardItem::create([
            'company_id' => $victim->id,
            'name' => 'รางวัลลับของอีกบริษัท',
            'cost_points' => 500,
            'stock_quantity' => 3,
            'is_active' => true,
            'reward_type' => 'physical',
        ]);

        $this->actingAs($attacker)->getJson("/api/v1/badges/{$badge->id}")->assertForbidden();
        $this->actingAs($attacker)->getJson("/api/v1/reward-items/{$reward->id}")->assertForbidden();
    }

    public function test_platform_wide_badges_and_announcements_stay_readable_by_everyone(): void
    {
        // The narrowing above must not swallow the platform's OWN rows.
        // company_id null means "addressed to every company", and a fix
        // that hid those would break the newsfeed and the badge catalogue
        // for every tenant at once — a louder failure than the leak, but
        // one the leak's own fix could very easily cause.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $platformBadge = Badge::factory()->create(['company_id' => null]);
        $platformPost = Announcement::create([
            'company_id' => null,
            'title' => 'ประกาศจากแพลตฟอร์ม',
            'content' => 'ถึงทุกบริษัท',
            'audience' => 'all_agents',
            'published_at' => now()->subDay(),
        ]);

        $this->actingAs($agent)->getJson("/api/v1/badges/{$platformBadge->id}")->assertOk();
        $this->actingAs($agent)->getJson("/api/v1/announcements/{$platformPost->id}")->assertOk();
    }
}
