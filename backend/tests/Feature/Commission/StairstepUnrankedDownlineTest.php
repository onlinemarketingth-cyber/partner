<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionPlanType;
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
use Tests\TestCase;

/**
 * WHAT A DOWNLINE WITH NO RANK COSTS THE COMPANY.
 *
 * ═══ THE BUG THIS FILE WAS WRITTEN FOR ═══
 *
 * payDifferentialOverride() used to read the child's rate as
 * `$childRank->rate_value ?? 0`. On a null rank that quietly became 0, and
 * a child rate of 0 makes `managerRate - childRate` the manager's FULL
 * rank rate rather than a difference. The plan's central promise — the
 * company never pays out more than the highest rank's rate in the chain —
 * was simply false whenever a downline had no rank.
 *
 * And that is not an edge case. `users.current_rank_id` starts NULL on
 * every agent ever created, and the ONLY thing that ever writes it is the
 * scheduled recalculation, which runs on the company's own cadence —
 * daily, weekly or MONTHLY. So the overpay fired on the first sale of
 * every new recruit, for up to a month, into ledger rows BR-4 forbids
 * correcting.
 *
 * Owner decision 2026-09-24 (choice 2ก): an un-ranked agent is treated as
 * holding the company's threshold-0 rank — not a guess, but the same
 * answer the next recalculation will write, since an agent with no volume
 * clears that rung anyway. A company with no threshold-0 rung has no
 * answer to borrow, so no row is written at all rather than an invented
 * one (CLAUDE.md Section 8 guardrail 1).
 */
class StairstepUnrankedDownlineTest extends TestCase
{
    use RefreshDatabase;

    private const SALE_SATANG = 1_000_000; // ฿10,000

    private function stairstepCompany(): Company
    {
        return Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::StairstepBreakaway->value,
        ]);
    }

    private function rank(Company $company, string $name, int $threshold, int $rateValue, int $sortOrder, bool $breakaway = false): AgentRank
    {
        return AgentRank::factory()->create([
            'company_id' => $company->id,
            'name' => $name,
            'volume_threshold' => $threshold,
            'sort_order' => $sortOrder,
            'rate_value' => $rateValue,
            'is_breakaway_rank' => $breakaway,
        ]);
    }

    /** current_rank_id is system-owned and not fillable — same bypass the engine itself uses. */
    private function setRank(User $user, AgentRank $rank): void
    {
        $user->forceFill(['current_rank_id' => $rank->id])->save();
    }

    private function sell(Company $company, User $seller): Referral
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::firstOrCreate(
            ['user_id' => $seller->id, 'cert_tier_id' => $tier->id],
            ['company_id' => $company->id, 'passed_at' => now()],
        );

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $seller->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => self::SALE_SATANG]);
        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
        ]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $seller->id,
            'product_id' => $product->id, 'branch' => 'Silom', 'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::CompleteRegistered, 'meeting_number' => null, 'submitted_at' => now(),
        ]);

        $this->closeSale($referral, $seller);

        return $referral;
    }

    /** Every stairstep override row written for one agent, in satang. */
    private function overridesFor(User $agent): array
    {
        return CommissionLedger::where('agent_id', $agent->id)
            ->where('earned_via', CommissionEarnedVia::StairstepOverride->value)
            ->pluck('amount_satang')
            ->all();
    }

    public function test_an_unranked_seller_is_priced_at_the_entry_rank_not_at_zero(): void
    {
        $company = $this->stairstepCompany();
        $this->rank($company, 'Bronze', threshold: 0, rateValue: 200, sortOrder: 1);       // 2% — the entry rung
        $gold = $this->rank($company, 'Gold', threshold: 5_000_000, rateValue: 700, sortOrder: 2); // 7%

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->setRank($manager, $gold);
        // $seller keeps current_rank_id = null — a recruit whose first sale
        // lands before the recalculation job has ever looked at them.

        $this->sell($company, $seller);

        // 7% - 2% = 5% of ฿10,000 = ฿500.
        $this->assertSame([50_000], $this->overridesFor($manager));
    }

    public function test_the_manager_no_longer_collects_their_whole_rate_from_an_unranked_seller(): void
    {
        /*
         * THE MUTATION GUARD. The assertion above passes just as happily if
         * someone restores the old `?? 0`, as long as the entry rank's rate
         * is also 0 — so this fixes the exact number the old code produced
         * and insists it is absent. 7% of ฿10,000 = ฿700 is what the
         * company used to pay for a recruit's first sale.
         */
        $company = $this->stairstepCompany();
        $this->rank($company, 'Bronze', threshold: 0, rateValue: 200, sortOrder: 1);
        $gold = $this->rank($company, 'Gold', threshold: 5_000_000, rateValue: 700, sortOrder: 2);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->setRank($manager, $gold);

        $this->sell($company, $seller);

        $this->assertNotContains(70_000, $this->overridesFor($manager));
    }

    public function test_a_company_with_no_entry_rank_writes_no_row_rather_than_guessing_one(): void
    {
        /*
         * A ladder whose cheapest rung still needs ฿50,000 of volume has no
         * opinion about somebody at ฿0. Borrowing that rung's rate would be
         * inventing a business value (BR-7); paying the manager their whole
         * rate is the overpay this change exists to remove. So: nothing,
         * and CommissionReadinessService is what tells the admin why.
         */
        $company = $this->stairstepCompany();
        $gold = $this->rank($company, 'Gold', threshold: 5_000_000, rateValue: 700, sortOrder: 1);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->setRank($manager, $gold);

        $this->sell($company, $seller);

        $this->assertSame([], $this->overridesFor($manager));
    }

    public function test_a_hop_that_cannot_be_priced_is_skipped_and_the_walk_carries_on(): void
    {
        /*
         * `continue`, not `break`. The pair (un-ranked seller, manager) has
         * no answer, but the pair above it — manager and their own manager,
         * both ranked — is perfectly well defined, and stopping the walk
         * there would silently strip an entire upline of its earnings
         * because of one missing rank two levels below.
         */
        $company = $this->stairstepCompany();
        $gold = $this->rank($company, 'Gold', threshold: 5_000_000, rateValue: 700, sortOrder: 1);
        $platinum = $this->rank($company, 'Platinum', threshold: 20_000_000, rateValue: 1_200, sortOrder: 2);

        $director = User::factory()->agent()->create(['company_id' => $company->id]);
        $manager = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $director->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->setRank($director, $platinum);
        $this->setRank($manager, $gold);

        $this->sell($company, $seller);

        $this->assertSame([], $this->overridesFor($manager), 'the unpriceable hop must pay nothing');
        // 12% - 7% = 5% of ฿10,000 = ฿500 — the hop above is untouched.
        $this->assertSame([50_000], $this->overridesFor($director));
    }

    public function test_the_substituted_entry_rank_also_answers_the_breakaway_question(): void
    {
        /*
         * "This child counts as the entry rank" has to mean one thing for
         * the whole iteration. A company that marks its threshold-0 rung as
         * a breakaway rank has said something strange but has said it
         * deliberately, and the walk must honour it rather than reading the
         * rung for its rate and ignoring its flag.
         */
        $company = $this->stairstepCompany();
        $this->rank($company, 'Bronze', threshold: 0, rateValue: 200, sortOrder: 1, breakaway: true);
        $gold = $this->rank($company, 'Gold', threshold: 5_000_000, rateValue: 700, sortOrder: 2);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->setRank($manager, $gold);

        $this->sell($company, $seller);

        $this->assertSame([], $this->overridesFor($manager));
    }

    public function test_a_ranked_seller_is_unaffected_by_any_of_this(): void
    {
        // The regression guard for the common path: nothing above may
        // change what a normally-ranked chain pays.
        $company = $this->stairstepCompany();
        $bronze = $this->rank($company, 'Bronze', threshold: 0, rateValue: 200, sortOrder: 1);
        $gold = $this->rank($company, 'Gold', threshold: 5_000_000, rateValue: 700, sortOrder: 2);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $this->setRank($manager, $gold);
        $this->setRank($seller, $bronze);

        $this->sell($company, $seller);

        $this->assertSame([50_000], $this->overridesFor($manager));
    }
}
