<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionBasis;
use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionPlanType;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Once a company has paid somebody, it may not change what it pays on or who
 * it pays.
 *
 * Owner, 2026-09-19: "ครั้งเดียวใช้ทั้งบริษัท และไม่ควรเปลี่ยนหากมียอดขาย
 * เกิดขึ้นแล้ว". Until today nothing enforced the second half — basis and plan
 * type were written with no precondition at all, and the audit log recorded
 * the switch after the fact. BR-4 makes every row written before it
 * permanently uncorrectable, so the switch leaves one promise and two sets of
 * rows that answer it differently.
 *
 * The tests below are mostly about the EDGES rather than the happy refusal,
 * because a guard this blunt is easy to get wrong in the direction that locks
 * a company out of its own setup screen: a no-op save, a first-time setup, an
 * unrelated third setting saved in the same request.
 */
class CommissionSettingsLockedBySalesTest extends TestCase
{
    use RefreshDatabase;

    private function company(array $attributes = []): Company
    {
        return Company::factory()->create(array_merge([
            'commission_plan_type' => CommissionPlanType::Unilevel->value,
            'commission_basis' => CommissionBasis::Price->value,
        ], $attributes));
    }

    private function withOneSale(Company $company): Company
    {
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'created_at' => now()->subMonth(),
        ]);

        return $company;
    }

    private function save(Company $company, array $payload)
    {
        return $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/v1/commission-settings', array_merge(['company_id' => $company->id], $payload));
    }

    // ── Before any sale: still a setup screen ───────────────────────────────

    public function test_a_company_with_no_sales_may_still_choose_its_basis_and_plan(): void
    {
        $company = $this->company();

        $this->save($company, [
            'commission_basis' => CommissionBasis::PointValue->value,
            'commission_plan_type' => CommissionPlanType::Binary->value,
        ])->assertOk();

        $company->refresh();
        $this->assertSame(CommissionBasis::PointValue, $company->commission_basis);
        $this->assertSame(CommissionPlanType::Binary, $company->commission_plan_type);
    }

    // ── After a sale: refused, and told why ─────────────────────────────────

    public function test_the_basis_cannot_change_once_commission_has_been_booked(): void
    {
        $company = $this->withOneSale($this->company());

        $this->save($company, ['commission_basis' => CommissionBasis::PointValue->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('commission_basis');

        $this->assertSame(CommissionBasis::Price, $company->fresh()->commission_basis);
    }

    public function test_the_plan_type_cannot_change_once_commission_has_been_booked(): void
    {
        $company = $this->withOneSale($this->company());

        $this->save($company, ['commission_plan_type' => CommissionPlanType::Binary->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('commission_plan_type');

        $this->assertSame(CommissionPlanType::Unilevel, $company->fresh()->commission_plan_type);
    }

    public function test_the_refusal_says_how_many_rows_and_since_when(): void
    {
        // A refusal that only says "not allowed" sends the admin to support.
        // The count and the date are what let them decide for themselves
        // whether this is a real block or a company they set up by mistake.
        $company = $this->withOneSale($this->company());

        $response = $this->save($company, ['commission_basis' => CommissionBasis::PointValue->value])
            ->assertStatus(422);

        $message = $response->json('errors.commission_basis.0');
        $this->assertStringContainsString('1 รายการ', $message);
        $this->assertStringContainsString(now()->subMonth()->format('d/m/Y'), $message);
    }

    // ── The edges that would lock somebody out ──────────────────────────────

    public function test_re_saving_the_value_already_in_place_is_not_a_change(): void
    {
        // The screen sends all three settings together. If a no-op counted as
        // a change, a company with sales could never save the third one again.
        $company = $this->withOneSale($this->company());

        $this->save($company, [
            'commission_basis' => CommissionBasis::Price->value,
            'commission_plan_type' => CommissionPlanType::Unilevel->value,
        ])->assertOk();
    }

    public function test_an_unrelated_setting_still_saves_on_a_company_with_sales(): void
    {
        // commission_override_mode has its own, different guard and is not
        // settled by past sales — the lock must not spread to it.
        $company = $this->withOneSale($this->company([
            'commission_override_mode' => CommissionOverrideMode::Additive->value,
        ]));

        $this->save($company, [
            'commission_basis' => CommissionBasis::Price->value,
            'commission_plan_type' => CommissionPlanType::Unilevel->value,
            'commission_override_mode' => CommissionOverrideMode::DeductFromCommission->value,
        ])->assertOk();

        $this->assertSame(
            CommissionOverrideMode::DeductFromCommission,
            $company->fresh()->commission_override_mode,
        );
    }

    public function test_another_companys_sales_do_not_lock_this_one(): void
    {
        // BR-6. The count is per company, and a shared-nothing mistake here
        // would freeze every company on the platform the moment one sold.
        $busy = $this->withOneSale($this->company());
        $quiet = $this->company();

        $this->save($quiet, ['commission_basis' => CommissionBasis::PointValue->value])->assertOk();

        $this->assertSame(CommissionBasis::PointValue, $quiet->fresh()->commission_basis);
        $this->assertSame(CommissionBasis::Price, $busy->fresh()->commission_basis);
    }

    public function test_a_reversal_row_still_counts_as_having_paid(): void
    {
        // A company whose only rows are reversals paid, and then unpaid, real
        // people under the old rule — the promise was still made.
        $company = $this->company();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'amount_satang' => -50_000,
            'earned_via' => CommissionEarnedVia::Reversal->value,
        ]);

        $this->save($company, ['commission_plan_type' => CommissionPlanType::Matrix->value])
            ->assertStatus(422);
    }

    // ── The screen is told BEFORE the press ─────────────────────────────────

    /**
     * 2026-09-21 — the refusal existed for two days with nothing on screen
     * saying so.
     *
     * Owner: "ที่เราเคยสรุปกันไว้ไม่ใช่เหรอว่าหากมีการขายเกิดขึ้นแล้ว การ Setup
     * เปลี่ยนแผนค่าแนะนำจะทำไม่ได้". It was, and the server enforced it — but
     * step 2 went on offering the switch under a banner reading "การสลับแผนมี
     * ผลกับการขายครั้งถัดไปเท่านั้น", and the admin found out by pressing.
     *
     * A rule the screen cannot see is a rule the screen will contradict.
     */
    private function read(Company $company)
    {
        return $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson("/api/v1/commission-settings?company_id={$company->id}");
    }

    public function test_the_settings_read_says_nothing_is_locked_before_the_first_sale(): void
    {
        $this->read($this->company())
            ->assertOk()
            ->assertJsonPath('data.plan_locked_by_sales.locked', false)
            ->assertJsonPath('data.plan_locked_by_sales.ledger_rows', 0)
            ->assertJsonPath('data.plan_locked_by_sales.first_ledger_at', null);
    }

    public function test_the_settings_read_reports_the_lock_with_the_same_facts_the_refusal_quotes(): void
    {
        /*
         * The count and the date are the whole point. "เปลี่ยนไม่ได้" with no
         * reason reads as a bug or a permission problem; naming how many rows
         * were settled and when the first one was is the difference between a
         * rule and an obstruction.
         */
        $company = $this->withOneSale($this->company());

        $this->read($company)
            ->assertOk()
            ->assertJsonPath('data.plan_locked_by_sales.locked', true)
            ->assertJsonPath('data.plan_locked_by_sales.ledger_rows', 1);

        $this->assertNotNull(
            $this->read($company)->json('data.plan_locked_by_sales.first_ledger_at'),
            'the screen formats the date itself — an admin reads Buddhist years — so it needs the timestamp, not a pre-formatted string',
        );
    }

    public function test_the_read_and_the_refusal_agree_on_the_row_count(): void
    {
        /*
         * THE REASON THEY SHARE ONE HELPER. Two expressions of one rule are
         * free to drift, and this pair would drift silently: the banner would
         * say "3 รายการ" over a refusal that said something else, and nobody
         * would notice until an admin quoted one at the other.
         */
        $company = $this->company();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        CommissionLedger::factory()->count(3)->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
        ]);

        $reported = $this->read($company)->json('data.plan_locked_by_sales.ledger_rows');

        $refusal = (string) $this->save($company, ['commission_plan_type' => CommissionPlanType::Matrix->value])
            ->assertStatus(422)
            ->json('errors.commission_plan_type.0');

        $this->assertSame(3, $reported);
        $this->assertStringContainsString("{$reported} รายการ", $refusal);
    }

    public function test_another_companys_sales_do_not_lock_this_ones_screen(): void
    {
        // BR-6, on the read side too. The count is per company, and a shared
        // banner would refuse a switch this company is entitled to make.
        $busy = $this->withOneSale($this->company());
        $quiet = $this->company();

        $this->read($quiet)->assertJsonPath('data.plan_locked_by_sales.locked', false);
        $this->read($busy)->assertJsonPath('data.plan_locked_by_sales.locked', true);
    }
}
