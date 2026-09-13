<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionRateType;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 2026-09-13 — POST /commission-rules/copy.
 *
 * The owner asked why a new company does not simply inherit a rate
 * ("ทำไมระบบเราไม่ดึงค่าคอมจากค่าเริ่มต้นมาตั้งเป็นค่าคอมมาตรฐาน"). It must
 * not: BR-7 forbids the system inventing a business value, and a guessed rate
 * is indistinguishable on screen from a decided one by the time it reaches a
 * commission_ledger row that BR-4 forbids correcting.
 *
 * Copying ON REQUEST is a different thing, and these tests pin the three
 * properties that keep it different: nothing is written without an explicit
 * confirmation, nothing already decided is overwritten, and nothing crosses a
 * tenant boundary (BR-6).
 */
class CommissionRateCopyTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/commission-rules/copy';

    public function test_a_preview_writes_nothing(): void
    {
        // The confirmation step the owner asked for, enforced where a frontend
        // bug cannot skip it.
        [$from, $to] = $this->twoCompanies();
        $this->companyDefault($from->id, 300);

        $this->actingAs($this->superAdmin())
            ->postJson(self::ENDPOINT, ['from_company_id' => $from->id, 'to_company_id' => $to->id, 'dry_run' => true])
            ->assertOk()
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.total_to_copy', 1);

        $this->assertSame(0, CommissionRule::withoutGlobalScopes()->where('company_id', $to->id)->count());
    }

    public function test_an_omitted_dry_run_flag_is_treated_as_a_preview(): void
    {
        /*
         * The safe direction. Laravel's boolean() reads a missing key as
         * false, which for a write endpoint would mean "a caller that forgot
         * the flag creates twelve rate rows". Inverted deliberately.
         */
        [$from, $to] = $this->twoCompanies();
        $this->companyDefault($from->id, 300);

        $this->actingAs($this->superAdmin())
            ->postJson(self::ENDPOINT, ['from_company_id' => $from->id, 'to_company_id' => $to->id])
            ->assertOk()
            ->assertJsonPath('data.dry_run', true);

        $this->assertSame(0, CommissionRule::withoutGlobalScopes()->where('company_id', $to->id)->count());
    }

    public function test_confirming_copies_the_live_rates(): void
    {
        [$from, $to] = $this->twoCompanies();
        $this->companyDefault($from->id, 300);

        $this->actingAs($this->superAdmin())
            ->postJson(self::ENDPOINT, ['from_company_id' => $from->id, 'to_company_id' => $to->id, 'dry_run' => false])
            ->assertOk()
            ->assertJsonPath('data.dry_run', false);

        $copy = CommissionRule::withoutGlobalScopes()->where('company_id', $to->id)->sole();

        $this->assertSame(300, $copy->rate_value);
        $this->assertNull($copy->product_id);
        $this->assertNull($copy->product_category_id);
    }

    public function test_the_copy_starts_today_and_is_open_ended(): void
    {
        /*
         * The dates are the copy's own. A borrowed effective_from claims this
         * company was paying that rate months ago — false, and it would make
         * assertNoOverlap() reserve a window nobody asked for, blocking the
         * backdated rule they might genuinely need later.
         */
        [$from, $to] = $this->twoCompanies();
        CommissionRule::withoutGlobalScopes()->create([
            'company_id' => $from->id,
            'product_id' => null,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 300,
            'effective_from' => now()->subMonths(6)->toDateString(),
            'effective_to' => now()->addMonths(6)->toDateString(),
        ]);

        $this->copy($from, $to);

        $copy = CommissionRule::withoutGlobalScopes()->where('company_id', $to->id)->sole();

        $this->assertSame(now()->toDateString(), $copy->effective_from->toDateString());
        $this->assertNull($copy->effective_to);
    }

    public function test_an_expired_rate_is_not_copied_and_is_not_reported_as_skipped(): void
    {
        /*
         * Absent from BOTH lists on purpose. An expired row is the source
         * company's history, not something the operator declined — listing it
         * would make the preview longer and less true.
         */
        [$from, $to] = $this->twoCompanies();
        CommissionRule::withoutGlobalScopes()->create([
            'company_id' => $from->id,
            'product_id' => null,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 300,
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => now()->subMonth()->toDateString(),
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->postJson(self::ENDPOINT, ['from_company_id' => $from->id, 'to_company_id' => $to->id, 'dry_run' => true])
            ->assertOk();

        $this->assertSame(0, $response->json('data.total_to_copy'));
        $this->assertSame([], $response->json('data.agent_rates.skipped'));
    }

    public function test_an_existing_rate_is_never_overwritten(): void
    {
        /*
         * THE ONE THAT MATTERS MOST. An existing rate is a decision somebody
         * made; a "copy" that replaced it would be an overwrite wearing a
         * friendlier word, and the operator would find out at a payout.
         */
        [$from, $to] = $this->twoCompanies();
        $this->companyDefault($from->id, 300);
        $mine = $this->companyDefault($to->id, 700);

        $response = $this->copy($from, $to);

        $this->assertSame(700, $mine->fresh()->rate_value, 'my own rate is untouched');
        $this->assertSame(1, CommissionRule::withoutGlobalScopes()->where('company_id', $to->id)->count(), 'and no second row was added beside it');
        $this->assertStringContainsString('มีอัตราของบริษัทนี้อยู่แล้ว', $response->json('data.agent_rates.skipped.0.reason'));
    }

    public function test_a_rate_for_the_source_companys_own_product_is_refused(): void
    {
        /*
         * BR-6. The target does not sell that product, so the copied rule
         * could never match anything — and it would hold another tenant's id
         * in a row that decides money.
         */
        [$from, $to] = $this->twoCompanies();
        $theirProduct = Product::factory()->create(['company_id' => $from->id]);
        $this->productRule($from->id, $theirProduct->id, 300);

        $response = $this->copy($from, $to);

        $this->assertSame(0, CommissionRule::withoutGlobalScopes()->where('company_id', $to->id)->count());
        $this->assertStringContainsString('เป็นของบริษัทต้นทาง', $response->json('data.agent_rates.skipped.0.reason'));
    }

    public function test_a_rate_for_a_shared_product_copies_fine(): void
    {
        // The control. Platform-owned rows are the case this feature exists
        // for — both companies genuinely sell them (ADR-040), so a rate for
        // one is meaningful for the other.
        [$from, $to] = $this->twoCompanies();
        $shared = Product::factory()->create(['company_id' => null]);
        $this->productRule($from->id, $shared->id, 300);

        $this->copy($from, $to);

        $this->assertSame(
            $shared->id,
            CommissionRule::withoutGlobalScopes()->where('company_id', $to->id)->sole()->product_id,
        );
    }

    public function test_leader_rates_are_copied_too(): void
    {
        // Step 4's rates are half of a working setup: without them the agent
        // is paid and the upline silently is not. A copy that brought only
        // half would reproduce exactly the gap the readiness banner nags about.
        [$from, $to] = $this->twoCompanies();
        CommissionOverrideRule::withoutGlobalScopes()->create([
            'company_id' => $from->id,
            'product_id' => null,
            'product_category_id' => null,
            'manager_cert_tier_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 150,
            'effective_from' => now()->subDay(),
        ]);

        $this->copy($from, $to);

        $this->assertSame(150, CommissionOverrideRule::withoutGlobalScopes()->where('company_id', $to->id)->sole()->rate_value);
    }

    public function test_copying_a_company_onto_itself_is_refused(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->superAdmin())
            ->postJson(self::ENDPOINT, ['from_company_id' => $company->id, 'to_company_id' => $company->id, 'dry_run' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('from_company_id');
    }

    public function test_a_company_admin_may_not_copy_rates(): void
    {
        // Same gate as writing one rate by hand: commission rate configuration
        // is Super Admin's alone since 2026-09-11, and twelve rows at once is
        // not a loophole in that.
        [$from, $to] = $this->twoCompanies();
        $this->companyDefault($from->id, 300);

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $to->id]))
            ->postJson(self::ENDPOINT, ['from_company_id' => $from->id, 'to_company_id' => $to->id, 'dry_run' => false])
            ->assertForbidden();

        $this->assertSame(0, CommissionRule::withoutGlobalScopes()->where('company_id', $to->id)->count());
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    /** @return array{0: Company, 1: Company} */
    private function twoCompanies(): array
    {
        return [Company::factory()->create(), Company::factory()->create()];
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function companyDefault(int $companyId, int $basisPoints): CommissionRule
    {
        return CommissionRule::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'product_id' => null,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $basisPoints,
            'effective_from' => now()->subDay(),
        ]);
    }

    private function productRule(int $companyId, int $productId, int $basisPoints): CommissionRule
    {
        return CommissionRule::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'product_id' => $productId,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $basisPoints,
            'effective_from' => now()->subDay(),
        ]);
    }

    private function copy(Company $from, Company $to): TestResponse
    {
        return $this->actingAs($this->superAdmin())
            ->postJson(self::ENDPOINT, ['from_company_id' => $from->id, 'to_company_id' => $to->id, 'dry_run' => false])
            ->assertOk();
    }
}
