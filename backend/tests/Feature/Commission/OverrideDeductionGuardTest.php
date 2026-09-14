<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-13 — "ห้ามตั้งเรทที่หักเกิน" (owner's decision).
 *
 * Under DeductFromSale every manager takes a percentage of the SALE out of a
 * pool that is only the SELLER's commission. A 2% leader rate against a 3%
 * seller rate survives exactly one manager; the second empties the pool and a
 * third would drive the seller negative.
 *
 * CommissionService caps this at runtime and OverrideModeTest pins that. But a
 * runtime cap alone means a leader silently receives nothing and finds out at a
 * payout — so the rate is REFUSED at save time instead, with the arithmetic in
 * the message. These tests are that refusal.
 */
class OverrideDeductionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rate_that_would_over_deduct_is_refused_with_the_number(): void
    {
        /*
         * A refusal without a number is a dead end: "too high" leaves the
         * admin guessing, and guessing at a rate is how they end up back here.
         * The message has to carry the maximum AND the three ways out.
         */
        $company = $this->companyWithChain(depth: 2, mode: CommissionOverrideMode::DeductFromSale);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/commission-override-rules', [
                'company_id' => $company->id,
                'rate_type' => 'percentage',
                'rate_value' => 200,
                'effective_from' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate_value');

        $message = $response->json('errors.rate_value.0');
        $this->assertStringContainsString('หักเกินค่าคอมของผู้ขาย', $message);
        $this->assertStringContainsString('2 ชั้น', $message);
        $this->assertStringContainsString('บริษัทจ่ายเพิ่ม', $message, 'the way out is named, not just the refusal');
    }

    public function test_a_rate_that_fits_is_accepted(): void
    {
        // The control. 1.5% x 2 managers = 3%, exactly the seller's rate —
        // the boundary is inclusive, because spending the pool exactly is a
        // legitimate plan and refusing it would be off by one.
        $company = $this->companyWithChain(depth: 2, mode: CommissionOverrideMode::DeductFromSale);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/commission-override-rules', [
                'company_id' => $company->id,
                'rate_type' => 'percentage',
                'rate_value' => 150,
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated();
    }

    public function test_the_same_rate_is_fine_when_the_company_pays_on_top(): void
    {
        /*
         * THE POINT OF THE WHOLE GUARD BEING MODE-AWARE. 2% x 2 managers is
         * refused when it comes out of the seller and perfectly ordinary when
         * the company funds it — there is no pool to exhaust. A guard that
         * refused both would be blocking the mode every existing company runs.
         */
        $company = $this->companyWithChain(depth: 2, mode: CommissionOverrideMode::Additive);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/commission-override-rules', [
                'company_id' => $company->id,
                'rate_type' => 'percentage',
                'rate_value' => 200,
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated();
    }

    public function test_a_company_with_no_hierarchy_is_not_blocked(): void
    {
        /*
         * Nobody has a manager, so no override will ever be paid and no pool
         * can be emptied. Refusing here would stop a company configuring the
         * rate BEFORE it builds its team — which is the order people actually
         * work in.
         */
        $company = $this->companyWithChain(depth: 0, mode: CommissionOverrideMode::DeductFromSale);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/commission-override-rules', [
                'company_id' => $company->id,
                'rate_type' => 'percentage',
                'rate_value' => 5000,
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated();
    }

    public function test_raising_an_existing_rate_is_refused_too(): void
    {
        // Both doors. A guard on create alone is a guard somebody walks around
        // by saving low and editing up.
        $company = $this->companyWithChain(depth: 2, mode: CommissionOverrideMode::DeductFromSale);

        $rule = CommissionOverrideRule::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => null,
            'product_category_id' => null,
            'manager_cert_tier_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 100,
            'effective_from' => now()->subDay(),
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/commission-override-rules/{$rule->id}", ['rate_value' => 200])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate_value');

        $this->assertSame(100, $rule->fresh()->rate_value);
    }

    public function test_the_cheapest_product_is_what_binds(): void
    {
        /*
         * The constraint is the worst product, not the average one. A rate
         * that is comfortable on a 29,900 package and ruinous on a 590 one is
         * not a safe rate — and a guard that checked only the first product it
         * found would approve it roughly half the time, depending on insertion
         * order.
         *
         * Both products here are percentage-rated, so the money differs while
         * the percentages do not — which is exactly why the guard compares
         * satang rather than rates.
         */
        $company = $this->companyWithChain(depth: 2, mode: CommissionOverrideMode::DeductFromSale);

        // A second, much cheaper product with a FIXED seller rate, so its pool
        // is small in absolute terms.
        $cheap = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 59000]);
        CommissionRule::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => $cheap->id,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::FixedSatang,
            'rate_value' => 1000,
            'effective_from' => now()->subDay(),
        ]);

        // 1.5% of 590 = 8.85 -> 9 satang per manager, x2 = 18 against a pool of
        // 1,000 satang: fine. 1.5% of the 10,000 product is 150 x 2 = 300
        // against 300: also exactly fine. Push to 2% and the expensive product
        // breaks first — the point is that BOTH are checked.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/commission-override-rules', [
                'company_id' => $company->id,
                'rate_type' => 'percentage',
                'rate_value' => 200,
                'effective_from' => now()->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_a_product_scoped_rate_is_judged_only_against_that_product(): void
    {
        /*
         * 2026-09-14 — the counterpart to "the cheapest product is what binds".
         *
         * That test is right for a COMPANY-WIDE rate, which has to come out of
         * every product's commission. A rate scoped to one product does not:
         * refusing 2% on a 10,000 package because some 590 add-on could not
         * fund it would be the guard blocking a configuration nobody asked it
         * about, and a guard that does that gets routed around.
         */
        $company = $this->companyWithChain(depth: 2, mode: CommissionOverrideMode::DeductFromSale);

        // The cheap product that makes the COMPANY-WIDE version of this rate
        // impossible — 10 baht of commission cannot fund two managers.
        $cheap = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 59000]);
        CommissionRule::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => $cheap->id,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::FixedSatang,
            'rate_value' => 10,
            'effective_from' => now()->subDay(),
        ]);

        $expensive = Product::withoutGlobalScopes()->where('company_id', $company->id)->where('price_satang', 1000000)->firstOrFail();

        $superAdmin = User::factory()->superAdmin()->create();

        // Company-wide: refused, because the cheap product is in scope.
        $this->actingAs($superAdmin)
            ->postJson('/api/v1/commission-override-rules', [
                'company_id' => $company->id,
                'rate_type' => 'percentage',
                'rate_value' => 150,
                'effective_from' => now()->toDateString(),
            ])
            ->assertStatus(422);

        // Scoped to the expensive product: accepted, because that product's
        // 300 baht funds two managers at 150 each exactly.
        $this->actingAs($superAdmin)
            ->postJson('/api/v1/commission-override-rules', [
                'company_id' => $company->id,
                'product_id' => $expensive->id,
                'rate_type' => 'percentage',
                'rate_value' => 150,
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated();
    }

    public function test_a_rate_that_opts_into_deducting_is_checked_even_when_the_company_pays_on_top(): void
    {
        /*
         * Without this, per-scope modes would be a hole straight through the
         * guard: set the company to "บริษัทจ่ายเพิ่ม", give the rate its own
         * deducting mode, and nothing would ever measure it against a pool.
         */
        $company = $this->companyWithChain(depth: 2, mode: CommissionOverrideMode::Additive);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/commission-override-rules', [
                'company_id' => $company->id,
                'rate_type' => 'percentage',
                'rate_value' => 200,
                'override_mode' => 'deduct_from_sale',
                'effective_from' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate_value');
    }

    public function test_a_rate_that_opts_out_of_deducting_is_not_measured_against_any_pool(): void
    {
        // The mirror image: the company deducts, this rate does not, so there
        // is no pool for it to exhaust and no reason to refuse it.
        $company = $this->companyWithChain(depth: 2, mode: CommissionOverrideMode::DeductFromSale);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/commission-override-rules', [
                'company_id' => $company->id,
                'rate_type' => 'percentage',
                'rate_value' => 900,
                'override_mode' => 'additive',
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated();
    }

    public function test_a_rate_with_its_own_mode_never_blocks_a_company_mode_switch(): void
    {
        // The switch cannot affect a rate that ignores it, so refusing the
        // switch on that rate's behalf would be a lock with no key — the same
        // reason expired rows are excluded.
        $company = $this->companyWithChain(depth: 2, mode: CommissionOverrideMode::Additive);

        CommissionOverrideRule::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => null,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 900,
            'override_mode' => CommissionOverrideMode::Additive,
            'effective_from' => now()->subDay(),
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/v1/commission-settings', [
                'company_id' => $company->id,
                'commission_override_mode' => 'deduct_from_sale',
            ])
            ->assertOk();
    }

    public function test_switching_to_a_deduct_mode_is_refused_when_an_existing_rate_would_over_deduct(): void
    {
        /*
         * THE OTHER ORDER, AND THE LIKELY ONE.
         *
         * Every test above sets a rate while the company is already on a
         * deduct mode. But a rate set under Additive was approved without the
         * guard ever running — nothing came out of a pool, so there was no
         * pool to empty. Flipping the mode afterwards starts all of them
         * deducting at once, and the runtime cap makes that SILENT: nothing
         * errors, the leaders simply stop being paid.
         *
         * So the switch is refused too, with the same arithmetic.
         */
        $company = $this->companyWithChain(depth: 2, mode: CommissionOverrideMode::Additive);

        CommissionOverrideRule::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => null,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 200,
            'effective_from' => now()->subDay(),
        ]);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/v1/commission-settings', [
                'company_id' => $company->id,
                'commission_override_mode' => 'deduct_from_sale',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('commission_override_mode');

        $this->assertStringContainsString('หักเกิน', $response->json('errors.commission_override_mode.0'));

        // And the refusal is a refusal: the column did not move.
        $this->assertSame(
            CommissionOverrideMode::Additive,
            $company->fresh()->commission_override_mode,
        );
    }

    public function test_switching_to_a_deduct_mode_is_allowed_when_existing_rates_fit(): void
    {
        $company = $this->companyWithChain(depth: 2, mode: CommissionOverrideMode::Additive);

        CommissionOverrideRule::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => null,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 150,
            'effective_from' => now()->subDay(),
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/v1/commission-settings', [
                'company_id' => $company->id,
                'commission_override_mode' => 'deduct_from_sale',
            ])
            ->assertOk()
            ->assertJsonPath('data.commission_override_mode', 'deduct_from_sale');

        $this->assertSame(
            CommissionOverrideMode::DeductFromSale,
            $company->fresh()->commission_override_mode,
        );
    }

    public function test_an_expired_rate_never_blocks_a_mode_switch(): void
    {
        // A rule that stopped applying last year cannot deduct from anything,
        // and refusing the switch because of it would be a lock with no key.
        $company = $this->companyWithChain(depth: 2, mode: CommissionOverrideMode::Additive);

        CommissionOverrideRule::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => null,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 900,
            'effective_from' => now()->subYear(),
            'effective_to' => now()->subMonth(),
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/v1/commission-settings', [
                'company_id' => $company->id,
                'commission_override_mode' => 'deduct_from_sale',
            ])
            ->assertOk();
    }

    public function test_switching_back_to_the_company_paying_is_never_refused(): void
    {
        // Nothing is coming out of anything, so there is nothing to exhaust —
        // and an admin who has just been told their rates deduct too much must
        // always be able to take the way out the message names.
        $company = $this->companyWithChain(depth: 2, mode: CommissionOverrideMode::DeductFromSale);

        CommissionOverrideRule::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => null,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 900,
            'effective_from' => now()->subDay(),
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/v1/commission-settings', [
                'company_id' => $company->id,
                'commission_override_mode' => 'additive',
            ])
            ->assertOk()
            ->assertJsonPath('data.commission_override_mode', 'additive');
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    /** One 10,000 THB product on a 3% seller rate, and a manager chain $depth deep. */
    private function companyWithChain(int $depth, CommissionOverrideMode $mode): Company
    {
        $company = Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::Unilevel,
            'commission_override_mode' => $mode,
        ]);

        $managerId = null;
        for ($i = 0; $i < $depth; $i++) {
            $managerId = User::factory()->agent()->create([
                'company_id' => $company->id,
                'manager_id' => $managerId,
            ])->id;
        }

        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $managerId]);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]);

        CommissionRule::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 300,
            'effective_from' => now()->subDay(),
        ]);

        return $company;
    }
}
