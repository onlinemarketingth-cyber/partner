<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Enums\UserRole;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Commission\CommissionHouseAccountService;
use App\Services\Commission\CommissionService;
use App\Services\Commission\CommissionWithdrawalService;
use App\Services\Platform\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 2026-09-15 — THE COMPANY AS A TEAM LEADER.
 *
 * Owner: "หัวหน้าทีมในที่นี้มีได้ 2 ความหมาย คือหัวหน้าทีมที่เป็น user จริงในระบบ
 * กับหัวหน้าทีมที่เป็นตัวบริษัทเองที่ได้ค่าคอมจากการขาย เช่น Thailife".
 *
 * The design that got chosen (แนวทาง 2 of four) puts the company in its own
 * hierarchy as a `users` row at the top, so the payout walk pays it without
 * learning a second kind of recipient. That decision buys safety in the money
 * path and spends it in the people path — a row that looks like a person now
 * exists, and every test below is about one of the two halves of that trade:
 *
 *   1. IT IS PAID LIKE A LEADER. The chain reaches it, the deduction comes out
 *      of the seller exactly as it does for a human, and a real leader in
 *      between is paid first.
 *   2. IT IS NOT TREATED LIKE A PERSON. It cannot withdraw, cannot be given a
 *      password, cannot be moved, and cannot be given a manager of its own.
 *      Each of those is a way for the company's own margin to become somebody
 *      else's money, and none of them errors loudly on its own.
 */
class CommissionHouseAccountTest extends TestCase
{
    use RefreshDatabase;

    // ── It is paid like a leader ──────────────────────────────────────

    public function test_the_company_is_paid_when_the_seller_has_nobody_above_them(): void
    {
        /*
         * The case the owner hit live: one agent, no upline, a leader rate
         * configured, and nobody was paid it. Before the house account that
         * was correct — there was no leader to fund — and it is why this
         * feature exists.
         */
        $world = $this->world(agentRate: 300, leaderRate: 200);
        $house = $this->houseAccounts()->create($world['company']);

        $this->sell($world);

        $this->assertSame(10000, $this->directAmount($world), 'seller keeps 300 - 200');
        $this->assertSame(20000, $this->amountFor($house), 'the company is paid the leader rate');
    }

    public function test_a_real_leader_and_the_company_are_both_paid_in_that_order(): void
    {
        /*
         * The consequence the owner accepted after seeing it three times: the
         * company does not only collect when there is no leader — it sits
         * ABOVE the leader, so both are paid and the seller funds both.
         */
        $world = $this->world(agentRate: 600, leaderRate: 200);
        $leader = $this->leaderAbove($world);
        $house = $this->houseAccounts()->create($world['company']);

        $this->sell($world);

        $this->assertSame(20000, $this->directAmount($world), 'seller keeps 600 - 200 - 200');
        $this->assertSame(20000, $this->amountFor($leader));
        $this->assertSame(20000, $this->amountFor($house));
    }

    public function test_the_company_is_last_in_the_queue_when_the_pool_runs_out(): void
    {
        /*
         * Nearest the seller is paid first, so the seat furthest away — the
         * company's, always — is the first to be shorted. Worth pinning
         * because it is the opposite of what most people assume about a house
         * position, and it is what decides whether a deep organisation earns
         * the company anything at all.
         */
        $world = $this->world(agentRate: 300, leaderRate: 200);
        $leader = $this->leaderAbove($world);
        $house = $this->houseAccounts()->create($world['company']);

        $this->sell($world);

        $this->assertSame(20000, $this->amountFor($leader), 'the human nearest the sale is paid in full');
        $this->assertSame(10000, $this->amountFor($house), 'the company gets only what is left');
        $this->assertSame(0, $this->directAmount($world), 'and the seller is emptied, never negative');
    }

    public function test_the_company_needs_no_certification_but_a_human_still_does(): void
    {
        /*
         * ADR-035's cert tier is a gate on being paid an override. The company
         * cannot sit an exam, so gating it would mean it is never paid however
         * anybody configures it — and the seller would never be charged for it
         * either. The exemption is narrowed to the one row the company points
         * at, which is what the second half of this test proves.
         */
        $world = $this->world(agentRate: 600, leaderRate: 200);
        $leader = $this->leaderAbove($world);
        UserCertification::query()->withoutGlobalScopes()->where('user_id', $leader->id)->delete();
        $house = $this->houseAccounts()->create($world['company']);

        $this->sell($world);

        $this->assertSame(0, $this->amountFor($leader), 'an uncertified human is skipped, as always');
        $this->assertSame(20000, $this->amountFor($house), 'the company is not');
        $this->assertSame(40000, $this->directAmount($world), 'and the seller is charged only for what was paid');
    }

    public function test_the_companys_ledger_row_claims_no_certification(): void
    {
        // The alternative design was to write it a certification row and touch
        // no code at all. It was rejected for exactly this field: a
        // qualification on a money row, for an entity that never earned one.
        $world = $this->world(agentRate: 300, leaderRate: 200);
        $house = $this->houseAccounts()->create($world['company']);

        $this->sell($world);

        $row = CommissionLedger::withoutGlobalScopes()->where('agent_id', $house->id)->sole();

        $this->assertNull($row->cert_tier_id_at_time);
        $this->assertSame(CommissionEarnedVia::Override, $row->earned_via);
    }

    // ── Wiring the seat in and out ────────────────────────────────────

    public function test_creating_the_seat_attaches_every_agent_who_had_no_upline(): void
    {
        $world = $this->world(agentRate: 300, leaderRate: 200);
        // Two people: the seller, who now reports to a human leader, and that
        // leader, who reports to nobody. Only the second one is missing an
        // upline, and only the second one should move.
        $leader = $this->leaderAbove($world);
        $loose = User::factory()->agent()->create(['company_id' => $world['company']->id, 'manager_id' => null]);

        $house = $this->houseAccounts()->create($world['company']);

        $this->assertSame($leader->id, $world['agent']->fresh()->manager_id, 'the seller keeps the human above them');
        $this->assertSame($house->id, $leader->fresh()->manager_id, 'the leader had nobody, so the company takes that place');
        $this->assertSame($house->id, $loose->fresh()->manager_id);
    }

    public function test_it_never_re_points_somebody_who_already_has_a_leader(): void
    {
        /*
         * The other half, and the one that would lose money quietly if it
         * broke: re-pointing an agent at the house would take their real
         * leader out of the chain and stop that person being paid.
         */
        $world = $this->world(agentRate: 300, leaderRate: 200);
        $leader = $this->leaderAbove($world);

        $this->houseAccounts()->create($world['company']);

        $this->assertSame($leader->id, $world['agent']->fresh()->manager_id);
    }

    public function test_an_agent_created_afterwards_joins_the_chain_by_themselves(): void
    {
        /*
         * There is more than one door into this table — self-registration, a
         * recruit link, an admin, an import — and an agent who comes through
         * any of them with no upline would sit outside the hierarchy, earning
         * the company nothing, with no error anywhere.
         */
        $world = $this->world(agentRate: 300, leaderRate: 200);
        $house = $this->houseAccounts()->create($world['company']);

        $newcomer = User::factory()->agent()->create(['company_id' => $world['company']->id, 'manager_id' => null]);

        $this->assertSame($house->id, $newcomer->fresh()->manager_id);
    }

    public function test_the_seat_itself_is_never_attached_to_anything(): void
    {
        $world = $this->world(agentRate: 300, leaderRate: 200);
        $house = $this->houseAccounts()->create($world['company']);

        $this->assertNull($house->fresh()->manager_id, 'the company is the root, or the walk has a cycle');
        $this->assertSame(UserRole::CompanyAdmin, $house->role, 'so every role = agent listing hides it for free');
    }

    public function test_switching_it_on_twice_does_not_create_a_second_seat(): void
    {
        // Two seats would both be paid on every sale — the most expensive way
        // this could go wrong.
        $world = $this->world(agentRate: 300, leaderRate: 200);

        $first = $this->houseAccounts()->create($world['company']);
        $second = $this->houseAccounts()->create($world['company']->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, User::withoutGlobalScopes()->where('company_id', $world['company']->id)->where('role', UserRole::CompanyAdmin->value)->count());
    }

    public function test_disabling_it_detaches_everyone_but_keeps_the_row(): void
    {
        /*
         * The row survives because commission_ledger restricts deleting a
         * payee, and it must: the rows the company was already paid on are
         * immutable (BR-4). Reinstating later returns the same row, so the
         * history stays on one payee.
         */
        $world = $this->world(agentRate: 300, leaderRate: 200);
        $house = $this->houseAccounts()->create($world['company']);

        $this->houseAccounts()->disable($world['company']->fresh());

        $this->assertNull($world['agent']->fresh()->manager_id);
        $this->assertNull($world['company']->fresh()->commission_house_user_id);
        $this->assertNotNull(User::withoutGlobalScopes()->find($house->id), 'the row itself is kept');
        $this->assertFalse($house->fresh()->isCommissionHouseAccount());
    }

    // ── It is not treated like a person ───────────────────────────────

    public function test_the_company_cannot_withdraw_its_own_margin(): void
    {
        /*
         * The seat accrues Pending ledger rows exactly like a leader does, and
         * every satang of them is money the company already holds. Without
         * this guard the balance query would offer it as something to request
         * a transfer of.
         */
        $world = $this->world(agentRate: 300, leaderRate: 200);
        $house = $this->houseAccounts()->create($world['company']);
        $this->sell($world);

        $withdrawals = app(CommissionWithdrawalService::class);

        $this->assertSame(0, $withdrawals->availableSatang($house->fresh()));

        $this->expectException(ValidationException::class);
        $withdrawals->request($house->fresh(), 10000);
    }

    public function test_an_ordinary_company_admin_is_untouched_by_that_guard(): void
    {
        // The control. The exemption is keyed on the company's pointer, never
        // on the role — a company admin is an ordinary person and keeps every
        // right this seat is denied.
        $world = $this->world(agentRate: 300, leaderRate: 200);
        $this->houseAccounts()->create($world['company']);
        $other = User::factory()->companyAdmin()->create(['company_id' => $world['company']->id]);

        $this->assertFalse($other->isCommissionHouseAccount());
        $this->assertSame(0, app(CommissionWithdrawalService::class)->availableSatang($other), 'zero because they earned nothing, not because they are blocked');
    }

    public function test_the_seat_refuses_a_password_a_manager_and_a_move(): void
    {
        /*
         * Three different doors into the same failure. A credential lets
         * somebody sign in as the company and ask for its margin; a manager
         * puts one of the company's own agents above it in the walk, paid on
         * every sale in the tenant; a move takes the seat away from the
         * company pointing at it.
         */
        $world = $this->world(agentRate: 300, leaderRate: 200);
        $house = $this->houseAccounts()->create($world['company']);
        $actor = User::factory()->superAdmin()->create();
        $users = app(UserService::class);
        $otherCompany = Company::factory()->create();

        foreach ([
            fn () => $users->resetPassword($house->fresh(), 'whatever-they-typed', $actor),
            fn () => $users->assignManager($house->fresh(), $world['agent']->id, $actor),
            fn () => $users->moveToCompany($house->fresh(), $otherCompany->id, $actor),
            fn () => $users->deactivate($house->fresh(), $actor),
        ] as $forbidden) {
            try {
                $forbidden();
                $this->fail('a control that belongs to a person was allowed on the company seat');
            } catch (ValidationException) {
                // expected
            }
        }

        $this->assertTrue($house->fresh()->isCommissionHouseAccount(), 'and it is still the seat afterwards');
    }

    // ── The endpoint ──────────────────────────────────────────────────

    public function test_a_super_admin_can_switch_it_on_and_the_screen_learns_the_new_chain(): void
    {
        /*
         * The payload carries the whole commission setting, not just the new
         * account, because switching this on deepens every chain by one and
         * that number is the ceiling step 4 offers as a maximum.
         */
        $world = $this->world(agentRate: 300, leaderRate: 200);
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)
            ->postJson('/api/v1/commission-house-account', ['company_id' => $world['company']->id, 'display_name' => 'ไทยประกันชีวิต'])
            ->assertOk();

        $response->assertJsonPath('data.commission_house_account.name', 'ไทยประกันชีวิต')
            ->assertJsonPath('data.commission_house_account.agents_under', 1)
            ->assertJsonPath('data.deepest_manager_chain', 1);
    }

    public function test_an_agent_may_not_switch_it_on(): void
    {
        $world = $this->world(agentRate: 300, leaderRate: 200);

        $this->actingAs($world['agent'])
            ->postJson('/api/v1/commission-house-account', ['company_id' => $world['company']->id])
            ->assertForbidden();
    }

    // ── The screens that list money and people ───────────────────────

    public function test_the_payout_list_marks_the_companys_own_row(): void
    {
        /*
         * The admin's จ่ายคอมมิชชั่น screen lists ledger rows with a
         * "จ่ายแล้ว" button on each. The company's row must not carry one:
         * marking it paid records a transfer the company made to itself, and
         * the number then disagrees with the bank forever (BR-4 — it cannot
         * be undone). The flag is what lets the screen hide the button.
         */
        $world = $this->world(agentRate: 300, leaderRate: 200);
        $house = $this->houseAccounts()->create($world['company']);
        $this->sell($world);
        $superAdmin = User::factory()->superAdmin()->create();

        $rows = collect($this->actingAs($superAdmin)
            ->getJson('/api/v1/commission-ledger?company_id='.$world['company']->id)
            ->assertOk()
            ->json('data'));

        $this->assertTrue($rows->firstWhere('agent.id', $house->id)['is_company_share']);
        $this->assertFalse($rows->firstWhere('agent.id', $world['agent']->id)['is_company_share']);
    }

    public function test_the_per_agent_summary_separates_the_companys_share(): void
    {
        // Same list, different screen: this one is a payout RUN, and the seat
        // has no bank details because it is not a person to transfer to.
        $world = $this->world(agentRate: 300, leaderRate: 200);
        $house = $this->houseAccounts()->create($world['company']);
        $this->sell($world);
        $superAdmin = User::factory()->superAdmin()->create();

        $rows = collect($this->actingAs($superAdmin)
            ->getJson('/api/v1/agent-commission-summary?company_id='.$world['company']->id)
            ->assertOk()
            ->json('data'));

        $this->assertTrue($rows->firstWhere('agent_id', $house->id)['is_company_share']);
        $this->assertFalse($rows->firstWhere('agent_id', $world['agent']->id)['is_company_share']);
    }

    public function test_the_user_list_says_which_row_is_the_seat_and_who_reports_to_it(): void
    {
        /*
         * Two different facts, and the console needs both: the seat itself
         * gets a label and loses the controls a person has, while an agent
         * reporting to it needs their upline shown as a statement — the seat
         * is not in the picker (it is not an agent), and clearing the field
         * would take the company out of its own payout chain.
         */
        $world = $this->world(agentRate: 300, leaderRate: 200);
        $house = $this->houseAccounts()->create($world['company']);
        $superAdmin = User::factory()->superAdmin()->create();

        $rows = collect($this->actingAs($superAdmin)
            ->getJson('/api/v1/users?company_id='.$world['company']->id)
            ->assertOk()
            ->json('data'));

        $seat = $rows->firstWhere('id', $house->id);
        $agent = $rows->firstWhere('id', $world['agent']->id);

        $this->assertTrue($seat['is_commission_house_account']);
        $this->assertFalse($seat['manager_is_commission_house_account']);
        $this->assertFalse($agent['is_commission_house_account']);
        $this->assertTrue($agent['manager_is_commission_house_account']);
    }

    public function test_the_user_list_offers_the_seat_none_of_a_persons_buttons(): void
    {
        /*
         * The user-management screen states its own rule: every button is
         * gated on the SERVER's answer for that row. So the refusal lives in
         * UserPolicy, and this asserts what the screen actually receives —
         * four copies of the condition in four v-ifs is four places for the
         * fifth screen to forget it.
         */
        $world = $this->world(agentRate: 300, leaderRate: 200);
        $house = $this->houseAccounts()->create($world['company']);
        $superAdmin = User::factory()->superAdmin()->create();

        $rows = collect($this->actingAs($superAdmin)
            ->getJson('/api/v1/users?with_permissions=1&company_id='.$world['company']->id)
            ->assertOk()
            ->json('data'));

        $seat = $rows->firstWhere('id', $house->id);
        $agent = $rows->firstWhere('id', $world['agent']->id);

        $this->assertFalse($seat['permissions']['update']);
        $this->assertFalse($seat['permissions']['deactivate']);
        $this->assertFalse($seat['permissions']['move_company']);
        // The control: an ordinary agent in the same company keeps everything.
        $this->assertTrue($agent['permissions']['update']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function houseAccounts(): CommissionHouseAccountService
    {
        return app(CommissionHouseAccountService::class);
    }

    /**
     * One company, one certified agent with no upline, one product at 10,000,
     * and a deducting mode — the shape every question here is about.
     *
     * @return array{company: Company, referral: Referral, agent: User, product: Product}
     */
    private function world(int $agentRate, int $leaderRate): array
    {
        $company = Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::Unilevel,
            'commission_override_mode' => CommissionOverrideMode::DeductFromSale,
        ]);

        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => null]);
        $this->certify($agent, $company);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]);

        CommissionRule::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $agentRate,
            'effective_from' => now()->subDay(),
        ]);

        CommissionOverrideRule::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'product_category_id' => null,
            'manager_cert_tier_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $leaderRate,
            'override_mode' => null,
            'effective_from' => now()->subDay(),
        ]);

        $referral = Referral::create([
            'company_id' => $company->id,
            'client_id' => Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id])->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'branch' => null,
            'preferred_time' => null,
            'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null,
            'submitted_at' => now(),
        ]);

        return compact('company', 'referral', 'agent', 'product');
    }

    /** A certified human leader directly above the seller. */
    private function leaderAbove(array $world): User
    {
        $leader = User::factory()->agent()->create(['company_id' => $world['company']->id, 'manager_id' => null]);
        $this->certify($leader, $world['company']);
        $world['agent']->forceFill(['manager_id' => $leader->id])->save();

        return $leader;
    }

    private function certify(User $user, Company $company): void
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);

        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);
    }

    private function sell(array $world): void
    {
        $ledger = app(CommissionService::class)->recordForReferral($world['referral']->fresh(['agent', 'product', 'company']));

        $this->assertNotNull($ledger, 'the fixture must produce a commission, or every assertion is vacuous');
    }

    private function directAmount(array $world): int
    {
        return (int) CommissionLedger::withoutGlobalScopes()
            ->where('agent_id', $world['agent']->id)
            ->where('earned_via', CommissionEarnedVia::Direct->value)
            ->sole()
            ->amount_satang;
    }

    private function amountFor(User $payee): int
    {
        return (int) CommissionLedger::withoutGlobalScopes()
            ->where('agent_id', $payee->id)
            ->where('earned_via', CommissionEarnedVia::Override->value)
            ->sum('amount_satang');
    }
}
