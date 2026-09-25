<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Models\AgentRank;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * THE SELLER'S OWN RATE COMES OFF THE RANK LADDER (ADR-043, choice ค).
 *
 * ═══ THE NUMBER THAT FORCED THIS ═══
 *
 * Owner decision 2026-09-24. Until then the seller was paid the flat
 * commission_rules rate while every manager above them was paid the
 * DIFFERENCE between rank rates. The two ladders were never tied together,
 * and the consequence was not a rounding quibble:
 *
 *   chain: seller → ผู้นำ(12%) → ผู้จัดการ(20%), flat seller rate 5%
 *
 *     seller sits at ขั้นเริ่มต้น(5%)   5 + (12-5) + (20-12) = 20%
 *     seller PROMOTED to ผู้นำ(12%)     5 +           (20-12) = 13%
 *
 * Promoting the seller cost the chain seven points and paid the promoted
 * agent not one satang more. A ladder built to reward climbing was
 * charging people to climb it, and the plan's central guarantee — the
 * company pays the highest rank in the chain and no more — was false at
 * every rung but the bottom one.
 *
 * ═══ WHAT IS DELIBERATELY UNCHANGED ═══
 *
 * Only Stairstep reads rank rates, so the other five plans are untouched;
 * an un-ranked seller still gets the flat rate (every agent starts
 * un-ranked, and paying a recruit nothing on their first sale would be a
 * worse bug than the one being fixed); and a company with no
 * commission_rule still pays nobody, because the renewal schedule reads
 * that rule and the readiness banner's red state is defined by it.
 */
class StairstepSellerRateTest extends TestCase
{
    use RefreshDatabase;

    private const SALE_SATANG = 1_000_000; // ฿10,000

    private function company(CommissionPlanType $plan = CommissionPlanType::StairstepBreakaway): Company
    {
        return Company::factory()->create(['commission_plan_type' => $plan->value]);
    }

    private function rank(Company $company, string $name, int $threshold, int $rateValue, int $sortOrder, bool $breakaway = false): AgentRank
    {
        return AgentRank::factory()->create([
            'company_id' => $company->id,
            'name' => $name,
            'volume_threshold' => $threshold,
            'sort_order' => $sortOrder,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $rateValue,
            'is_breakaway_rank' => $breakaway,
        ]);
    }

    /** The UAT ladder: 5 / 12 / 20 (breakaway). */
    private function ladder(Company $company): array
    {
        return [
            'entry' => $this->rank($company, 'UAT ขั้นเริ่มต้น', 0, 500, 1),
            'leader' => $this->rank($company, 'UAT ขั้นผู้นำ', 5_000_000, 1_200, 2),
            'manager' => $this->rank($company, 'UAT ขั้นผู้จัดการ', 20_000_000, 2_000, 3, breakaway: true),
        ];
    }

    private function setRank(User $user, ?AgentRank $rank): void
    {
        $user->forceFill(['current_rank_id' => $rank?->id])->save();
    }

    /**
     * One completed sale. $flatRateBp is the commission_rules figure — the
     * one a ranked seller must now IGNORE.
     */
    private function sell(Company $company, User $seller, int $flatRateBp = 500, bool $withRule = true): Referral
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::firstOrCreate(
            ['user_id' => $seller->id, 'cert_tier_id' => $tier->id],
            ['company_id' => $company->id, 'passed_at' => now()],
        );

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $seller->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => self::SALE_SATANG]);

        if ($withRule) {
            CommissionRule::factory()->create([
                'company_id' => $company->id,
                'cert_tier_id' => null,
                'product_id' => $product->id,
                'product_category_id' => null,
                'rate_type' => CommissionRateType::Percentage,
                'rate_value' => $flatRateBp,
                'effective_from' => now()->subYear()->toDateString(),
                'effective_to' => null,
            ]);
        }

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $seller->id,
            'product_id' => $product->id, 'branch' => 'Silom', 'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::CompleteRegistered, 'meeting_number' => null, 'submitted_at' => now(),
        ]);

        $this->closeSale($referral, $seller);

        return $referral;
    }

    private function directSatang(User $agent): int
    {
        return (int) CommissionLedger::where('agent_id', $agent->id)
            ->where('earned_via', CommissionEarnedVia::Direct->value)
            ->sum('amount_satang');
    }

    private function totalPaid(Company $company): int
    {
        return (int) CommissionLedger::where('company_id', $company->id)->sum('amount_satang');
    }

    // --- The headline ---

    public function test_a_promoted_seller_is_paid_their_own_rank_not_the_flat_rate(): void
    {
        $company = $this->company();
        ['leader' => $leader, 'manager' => $managerRank] = $this->ladder($company);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->setRank($manager, $managerRank);
        $this->setRank($seller, $leader);

        // Flat rate 5%, seller's rank 12%.
        $this->sell($company, $seller, flatRateBp: 500);

        // 12% of ฿10,000 = ฿1,200 — not the ฿500 the flat rate would give.
        $this->assertSame(120_000, $this->directSatang($seller));
    }

    public function test_the_chain_no_longer_loses_seven_points_when_the_seller_climbs(): void
    {
        /*
         * THE REGRESSION GUARD FOR THE WHOLE DECISION.
         *
         * Same chain, same sale, seller one rung higher than the bottom.
         * The old code paid ฿1,300 here — ฿500 to the seller off the flat
         * rate and ฿800 to the manager off the rank difference — and the
         * missing ฿700 went nowhere at all.
         */
        $company = $this->company();
        ['leader' => $leader, 'manager' => $managerRank] = $this->ladder($company);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->setRank($manager, $managerRank);
        $this->setRank($seller, $leader);

        $this->sell($company, $seller, flatRateBp: 500);

        $this->assertSame(200_000, $this->totalPaid($company), 'the chain must pay the top rank, 20%');
        $this->assertNotSame(130_000, $this->totalPaid($company));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function rungs(): array
    {
        return [
            'seller at the entry rung' => ['entry', 50_000],
            'seller one rung up' => ['leader', 120_000],
            'seller level with their manager' => ['manager', 200_000],
        ];
    }

    #[DataProvider('rungs')]
    public function test_the_chain_pays_the_top_rank_wherever_the_seller_sits(string $rung, int $expectedSellerSatang): void
    {
        /*
         * The property the whole plan rests on, asserted directly: the
         * differentials telescope, so the company's outlay is the highest
         * rank in the chain and nothing else — 20% of ฿10,000 = ฿2,000 —
         * no matter which rung the seller occupies.
         *
         * At the top rung the manager earns nothing (differential 0, and
         * that rung is the breakaway one besides). The total is unchanged;
         * only its distribution moves.
         */
        $company = $this->company();
        $ladder = $this->ladder($company);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->setRank($manager, $ladder['manager']);
        $this->setRank($seller, $ladder[$rung]);

        $this->sell($company, $seller, flatRateBp: 500);

        $this->assertSame($expectedSellerSatang, $this->directSatang($seller));
        $this->assertSame(200_000, $this->totalPaid($company));
    }

    public function test_climbing_a_rung_now_raises_what_the_climber_earns(): void
    {
        // Two sales by the same agent with a promotion in between — the
        // incentive the ladder was supposed to create and did not.
        $company = $this->company();
        ['entry' => $entry, 'leader' => $leader] = $this->ladder($company);

        $seller = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->setRank($seller, $entry);
        $this->sell($company, $seller, flatRateBp: 500);

        $this->assertSame(50_000, $this->directSatang($seller));

        $this->setRank($seller, $leader);
        $this->sell($company, $seller, flatRateBp: 500);

        // ฿500 then ฿1,200. Under the old rule the second sale paid ฿500 too.
        $this->assertSame(170_000, $this->directSatang($seller));
    }

    // --- The fallback ---

    public function test_a_seller_with_no_rank_is_still_paid_the_flat_rate(): void
    {
        /*
         * current_rank_id starts NULL on every agent and only the
         * scheduled recalculation writes it, so this is the path EVERY
         * recruit's first sale takes. Paying them nothing here would have
         * been a worse bug than the one ADR-043 fixes.
         */
        $company = $this->company();
        $this->ladder($company);

        $seller = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->setRank($seller, null);

        $this->sell($company, $seller, flatRateBp: 700);

        $this->assertSame(70_000, $this->directSatang($seller));
    }

    public function test_a_ranked_seller_still_needs_a_commission_rule_to_be_paid_at_all(): void
    {
        /*
         * The conservative half of the decision. The ladder could have
         * supplied a rate on its own, but the renewal schedule reads the
         * rule and the readiness banner's RED state is defined as "no
         * product resolves to a rate" — letting a ladder satisfy that
         * would have quietly redefined what red means.
         */
        $company = $this->company();
        ['entry' => $entry] = $this->ladder($company);

        $seller = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->setRank($seller, $entry);

        $this->sell($company, $seller, withRule: false);

        $this->assertSame(0, $this->totalPaid($company));
    }

    // --- The other five plans ---

    public function test_a_unilevel_company_ignores_the_rank_ladder_entirely(): void
    {
        /*
         * agent_ranks is shared with Generation and survives a plan
         * switch, so a Unilevel company can be holding a ladder and a
         * ranked agent. Only Stairstep measures anything in rank rates —
         * reading one here would silently reprice a plan nobody asked to
         * change.
         */
        $company = $this->company(CommissionPlanType::Unilevel);
        ['leader' => $leader] = $this->ladder($company);

        $seller = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->setRank($seller, $leader); // 12%, and it must not be used

        $this->sell($company, $seller, flatRateBp: 500);

        $this->assertSame(50_000, $this->directSatang($seller));
    }

    // --- Splits ---

    public function test_both_halves_of_a_split_sale_carry_the_referring_agents_rank_rate(): void
    {
        /*
         * A split divides one commission; it does not create a second
         * sale. Reading the co-agent's own rank would break the invariant
         * that the two rows sum EXACTLY to the sale's commission, and the
         * co-agent's own hierarchy is already out of scope (TASK-026).
         */
        $company = $this->company();
        ['leader' => $leader, 'entry' => $entry] = $this->ladder($company);

        $seller = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->setRank($seller, $leader); // 12%
        $this->setRank($coAgent, $entry); // 5% — must not be read

        $referral = $this->sell($company, $seller, flatRateBp: 500);

        // The split is applied by the service only when the company has it
        // switched on; this test asserts the rate carried, which holds on
        // either branch.
        $rates = CommissionLedger::where('referral_id', $referral->id)
            ->where('earned_via', CommissionEarnedVia::Direct->value)
            ->pluck('rate_applied')
            ->unique()
            ->values()
            ->all();

        $this->assertSame([1_200], $rates);
    }
}
