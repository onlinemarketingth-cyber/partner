<?php

namespace Tests\Feature\Catalog;

use App\Enums\CommissionRateType;
use App\Models\CertTier;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\Platform\PlatformCommissionSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// TASK-196 §2.3/§2.4 — server-side commission-rate-cap enforcement on
// POST/PUT /commission-rules, independent of the frontend live check.
//
// Every test uses a product priced at exactly 10,000 satang (100.00 THB)
// so that under the default 3000-basis-point (30.00%) cap, 1 basis point
// (0.01%) of that price is EXACTLY 1 satang — this makes the
// fixed_satang boundary ("1 basis point over" per §2.4) an exact integer
// value (3001 satang) rather than something that only crosses the cap
// after a rounding step, which is the whole point of a boundary test.
class CommissionRuleRateCapTest extends TestCase
{
    use RefreshDatabase;

    private const CAPPED_PRICE_SATANG = 10_000;

    // PlatformCommissionSettingService::CACHE_KEY uses CACHE_STORE=array
    // (phpunit.xml), which is an in-memory store that OUTLIVES a single
    // test's DB transaction rollback (RefreshDatabase rolls back the DB,
    // not the cache) — a previous test file writing a different cap via
    // the real update() path would otherwise leak a stale cached value
    // into this file's tests. Same class of pitfall
    // OrderPaymentNotificationTest::enablePlatformMail() already documents
    // for PlatformMailSettingService; forgetting the key in setUp()
    // guarantees every test here actually reads the migration-seeded 3000
    // default (or whatever it explicitly sets) instead of a leftover
    // value from another test class.
    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(PlatformCommissionSettingService::CACHE_KEY);
    }

    private function makeAdminAndProduct(): array
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $product = Product::factory()->for($company)->create(['price_satang' => self::CAPPED_PRICE_SATANG]);

        return [$admin, $tier, $product];
    }

    // -----------------------------------------------------------------
    // percentage — boundary at exactly the cap (3000 bps = 30.00%)
    // -----------------------------------------------------------------

    public function test_percentage_rate_exactly_at_the_cap_is_allowed_on_create(): void
    {
        [$admin, $tier, $product] = $this->makeAdminAndProduct();

        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 3000,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();
    }

    public function test_percentage_rate_one_basis_point_over_the_cap_is_rejected_on_create(): void
    {
        [$admin, $tier, $product] = $this->makeAdminAndProduct();

        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 3001,
            'effective_from' => now()->toDateString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rate_value');
    }

    public function test_percentage_rate_exactly_at_the_cap_is_allowed_on_update(): void
    {
        [$admin, $tier, $product] = $this->makeAdminAndProduct();

        $rule = CommissionRule::factory()->create([
            'company_id' => $admin->company_id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 100,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/commission-rules/{$rule->id}", ['rate_value' => 3000])
            ->assertOk();
    }

    public function test_percentage_rate_one_basis_point_over_the_cap_is_rejected_on_update(): void
    {
        [$admin, $tier, $product] = $this->makeAdminAndProduct();

        $rule = CommissionRule::factory()->create([
            'company_id' => $admin->company_id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 100,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/commission-rules/{$rule->id}", ['rate_value' => 3001])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rate_value');
    }

    // -----------------------------------------------------------------
    // fixed_satang — same boundary, against the 10,000-satang product
    // (3000 satang = 30.00% of price exactly; 3001 satang = 30.01%).
    // -----------------------------------------------------------------

    public function test_fixed_satang_rate_exactly_at_the_cap_is_allowed_on_create(): void
    {
        [$admin, $tier, $product] = $this->makeAdminAndProduct();

        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::FixedSatang->value,
            'rate_value' => 3000,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();
    }

    public function test_fixed_satang_rate_one_basis_point_over_the_cap_is_rejected_on_create(): void
    {
        [$admin, $tier, $product] = $this->makeAdminAndProduct();

        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::FixedSatang->value,
            'rate_value' => 3001,
            'effective_from' => now()->toDateString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rate_value');
    }

    public function test_fixed_satang_rate_exactly_at_the_cap_is_allowed_on_update(): void
    {
        [$admin, $tier, $product] = $this->makeAdminAndProduct();

        $rule = CommissionRule::factory()->create([
            'company_id' => $admin->company_id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::FixedSatang,
            'rate_value' => 100,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/commission-rules/{$rule->id}", ['rate_value' => 3000])
            ->assertOk();
    }

    public function test_fixed_satang_rate_one_basis_point_over_the_cap_is_rejected_on_update(): void
    {
        [$admin, $tier, $product] = $this->makeAdminAndProduct();

        $rule = CommissionRule::factory()->create([
            'company_id' => $admin->company_id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::FixedSatang,
            'rate_value' => 100,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/commission-rules/{$rule->id}", ['rate_value' => 3001])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rate_value');
    }

    // -----------------------------------------------------------------
    // Switching rate_type on update must re-evaluate against the cap too
    // (spec §3.2's frontend concern, mirrored server-side): a value that
    // was fine as fixed_satang can be over cap once reinterpreted as a
    // percentage against the same product.
    // -----------------------------------------------------------------

    public function test_switching_rate_type_on_update_re_evaluates_the_cap(): void
    {
        [$admin, $tier, $product] = $this->makeAdminAndProduct();

        // 3000 satang = exactly 30.00% of the 10,000-satang product — at
        // the cap as fixed_satang.
        $rule = CommissionRule::factory()->create([
            'company_id' => $admin->company_id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::FixedSatang,
            'rate_value' => 3000,
        ]);

        // Reinterpreting the SAME stored rate_value (3000) as a
        // percentage means 3000 basis points = 30.00% too — still
        // exactly at the cap, so this one is still allowed...
        $this->actingAs($admin)
            ->putJson("/api/v1/commission-rules/{$rule->id}", ['rate_type' => CommissionRateType::Percentage->value])
            ->assertOk();

        // ...but flipping to a rate_value that is fine as fixed_satang
        // (10,000 satang = 100% of price, clearly over cap already — use
        // a case that is over the cap only once reinterpreted) confirms
        // the check runs again on type change, not just on value change.
        $rule2 = CommissionRule::factory()->create([
            'company_id' => $admin->company_id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::FixedSatang,
            'rate_value' => 3001,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/commission-rules/{$rule2->id}", ['rate_type' => CommissionRateType::Percentage->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rate_value');
    }

    // -----------------------------------------------------------------
    // Company-wide / category-wide rules (product_id null) — §2.3's
    // documented judgment call: no single product price to check
    // against, so the cap check is a no-op, not a false rejection or a
    // silent pass-through of a broken check.
    // -----------------------------------------------------------------

    public function test_company_wide_default_rule_is_not_subject_to_the_cap_check(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();

        // An extreme rate that would fail against any real product's
        // price, if it were being checked at all.
        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $tier->id,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 9999,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();
    }

    // -----------------------------------------------------------------
    // The cap itself is admin-editable config (BR-7) — lowering it makes
    // a previously-fine rule newly rejected on its next write.
    // -----------------------------------------------------------------

    public function test_a_lowered_cap_rejects_a_rate_that_was_previously_fine(): void
    {
        [$admin, $tier, $product] = $this->makeAdminAndProduct();

        // Via the Service (not a raw Eloquent update) so the short-lived
        // Cache::remember() key is invalidated the same way a real
        // PUT /platform/commission-cap request would invalidate it
        // (CACHE_STORE=array in phpunit.xml persists across tests within
        // the same process — see OrderPaymentNotificationTest's own
        // Cache::forget() precedent for the same class of pitfall).
        app(PlatformCommissionSettingService::class)->update(['max_commission_rate_basis_points' => 1000], $admin);

        $this->actingAs($admin)->postJson('/api/v1/commission-rules', [
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage->value,
            // Would have been comfortably under the default 30% cap, but
            // now exceeds the lowered 10% one.
            'rate_value' => 1500,
            'effective_from' => now()->toDateString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rate_value');
    }
}
