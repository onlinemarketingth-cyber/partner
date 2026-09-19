<?php

namespace Tests\Feature\Commission;

use App\Enums\IdDocumentType;
use App\Enums\PaymentStatus;
use App\Models\CommissionLedger;
use App\Models\CommissionWithdrawalRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\Commission\CommissionWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ภาษีหัก ณ ที่จ่าย on an agent payout.
 *
 * ═══ WHAT WAS MISSING ═══
 *
 * A Thai company paying commission to an individual withholds tax at the
 * point of transfer and remits it in that person's name. The supplier side
 * has modelled this since 2026-10-01; the agent side computed what the agent
 * had earned and told an admin to transfer exactly that. Every company
 * running this has therefore either transferred the gross — leaving itself
 * liable for tax it failed to withhold — or done the subtraction by hand,
 * with the system's records showing a figure nobody ever transferred.
 *
 * ═══ THE INVARIANT EVERY TEST HERE IS DEFENDING ═══
 *
 * `amount_satang` stays the GROSS and the ledger is untouched by the tax.
 * The agent earned the gross; the company pays part of it to the Revenue
 * Department in the agent's name; the agent is credited with it when they
 * file. Netting the tax out of the earning would make an agent's balance and
 * their own withholding certificate disagree for ever.
 */
class WithholdingTaxOnPayoutTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: User} */
    private function world(?int $whtRateBp): array
    {
        $company = Company::factory()->create([
            'min_withdrawal_satang' => null,
            'wht_rate' => $whtRateBp,
        ]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // Complete payout details, or the request is refused before it
        // reaches what these tests are about.
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'national_id' => '1234567890123',
            'id_document_type' => IdDocumentType::cases()[0],
            'bank_name' => 'กสิกรไทย',
            'bank_account_number' => '1234567890',
            'bank_account_holder_name' => 'สมชาย ใจดี',
        ]);

        return [$company, $admin, $agent];
    }

    /** @param list<int> $amountsSatang */
    private function owe(Company $company, User $payee, array $amountsSatang): void
    {
        foreach ($amountsSatang as $satang) {
            CommissionLedger::factory()->create([
                'company_id' => $company->id,
                'agent_id' => $payee->id,
                'amount_satang' => $satang,
                'payment_status' => PaymentStatus::Pending,
                'paid_at' => null,
            ]);
        }
    }

    // ── The promise that nothing moved ──────────────────────────────────────

    public function test_a_company_with_no_rate_set_pays_exactly_what_it_paid_before(): void
    {
        /*
         * The whole safety argument, and first on purpose. Every company is
         * in this state today: no rate, therefore no withholding, therefore
         * a net that equals the gross to the satang.
         */
        [$company, , $agent] = $this->world(whtRateBp: null);
        $this->owe($company, $agent, [150_000]);

        $request = app(CommissionWithdrawalService::class)->request($agent, 150_000);

        $this->assertSame(150_000, $request->amount_satang);
        $this->assertNull($request->wht_rate_at_time);
        $this->assertSame(0, $request->wht_satang);
        $this->assertSame(150_000, $request->netTransferSatang());
    }

    // ── The deduction ───────────────────────────────────────────────────────

    public function test_the_companys_rate_is_withheld_and_the_three_figures_are_recorded(): void
    {
        [$company, , $agent] = $this->world(whtRateBp: 300); // 3.00%
        $this->owe($company, $agent, [150_000]);

        $request = app(CommissionWithdrawalService::class)->request($agent, 150_000);

        $this->assertSame(150_000, $request->amount_satang, 'the gross is what the agent earned');
        $this->assertSame(300, $request->wht_rate_at_time);
        $this->assertSame(4_500, $request->wht_satang, '3% of 1,500.00 THB');
        $this->assertSame(145_500, $request->netTransferSatang(), 'what actually leaves the bank');
    }

    public function test_the_tax_does_not_touch_the_ledger_or_the_agents_balance(): void
    {
        /*
         * THE INVARIANT. If withholding ever starts shrinking what a payout
         * draws from the ledger, an agent's remaining balance silently grows
         * by the tax on every payout and nobody can say where it came from.
         */
        [$company, , $agent] = $this->world(whtRateBp: 300);
        $this->owe($company, $agent, [150_000]);

        $service = app(CommissionWithdrawalService::class);
        $request = $service->request($agent, 150_000);

        $this->assertSame(
            150_000,
            (int) $request->items()->sum('allocated_satang'),
            'the allocation follows the GROSS — the tax is not a smaller draw on the ledger',
        );

        $this->assertSame(0, $service->availableSatang($agent->fresh()));
    }

    public function test_the_rate_is_a_snapshot_and_a_later_change_does_not_move_an_open_payout(): void
    {
        // Same reasoning as the bank_* snapshot beside it: an admin approves
        // a figure on Monday and accounting transfers on Thursday. An owner
        // editing the rate in between must not silently change what was
        // approved.
        [$company, , $agent] = $this->world(whtRateBp: 300);
        $this->owe($company, $agent, [150_000]);

        $request = app(CommissionWithdrawalService::class)->request($agent, 150_000);

        $company->update(['wht_rate' => 1_000]); // 10%

        $this->assertSame(300, $request->fresh()->wht_rate_at_time);
        $this->assertSame(4_500, $request->fresh()->wht_satang);
    }

    public function test_the_tax_is_truncated_never_rounded_up(): void
    {
        /*
         * 3% of 333 satang is 9.99 satang. intdiv gives 9, round() would give
         * 10 — a satang more of somebody's money than the rate allows, and a
         * satang of disagreement with SupplierPayoutService, which truncates
         * the same arithmetic for the same obligation.
         */
        [$company, , $agent] = $this->world(whtRateBp: 300);
        $this->owe($company, $agent, [333]);

        $request = app(CommissionWithdrawalService::class)->request($agent, 333);

        $this->assertSame(9, $request->wht_satang);
        $this->assertSame(324, $request->netTransferSatang());
    }

    public function test_an_admin_raised_payout_is_withheld_the_same_way(): void
    {
        // Both doors, one room: a payout the company raises and one the agent
        // asks for must not be taxed differently.
        [$company, $admin, $agent] = $this->world(whtRateBp: 300);
        $this->owe($company, $agent, [150_000]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-withdrawals/payout', [
                'agent_id' => $agent->id,
                'expected_total_satang' => 150_000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount_satang', 150_000)
            ->assertJsonPath('data.wht_satang', 4_500)
            ->assertJsonPath('data.net_transfer_satang', 145_500);
    }

    public function test_a_row_written_before_withholding_existed_reports_its_gross_as_its_net(): void
    {
        // net_satang is NULL on every historical row, and the truth about
        // such a row is that nothing was withheld. A payout screen must
        // render its real amount, not an empty field or a zero.
        [$company, , $agent] = $this->world(whtRateBp: null);
        $this->owe($company, $agent, [150_000]);

        $request = app(CommissionWithdrawalService::class)->request($agent, 150_000);
        CommissionWithdrawalRequest::withoutGlobalScopes()
            ->where('id', $request->id)
            ->update(['net_satang' => null, 'wht_satang' => 0, 'wht_rate_at_time' => null]);

        $this->assertSame(150_000, $request->fresh()->netTransferSatang());
    }

    // ── The setting ─────────────────────────────────────────────────────────

    public function test_the_rate_is_saved_and_audited_through_the_withdrawal_settings_endpoint(): void
    {
        [$company, $admin] = $this->world(whtRateBp: null);

        $this->actingAs($admin)
            ->putJson('/api/v1/commission-withdrawal-settings', [
                'min_withdrawal_satang' => null,
                'wht_rate' => 300,
            ])
            ->assertOk()
            ->assertJsonPath('wht_rate', 300);

        $this->assertSame(300, $company->fresh()->wht_rate);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id,
            'action' => 'settings.commission_withholding_tax_updated',
        ]);
    }

    public function test_saving_only_the_minimum_leaves_an_existing_rate_alone(): void
    {
        // The screen that exists today sends only min_withdrawal_satang. It
        // must not clear a tax rate it has never heard of.
        [$company, $admin] = $this->world(whtRateBp: 300);

        $this->actingAs($admin)
            ->putJson('/api/v1/commission-withdrawal-settings', ['min_withdrawal_satang' => 50_000])
            ->assertOk();

        $this->assertSame(300, $company->fresh()->wht_rate);
    }

    public function test_an_explicit_null_clears_the_rate(): void
    {
        // The other half: "no withholding" is a real instruction and must be
        // distinguishable from "not mentioned".
        [$company, $admin] = $this->world(whtRateBp: 300);

        $this->actingAs($admin)
            ->putJson('/api/v1/commission-withdrawal-settings', [
                'min_withdrawal_satang' => null,
                'wht_rate' => null,
            ])
            ->assertOk();

        $this->assertNull($company->fresh()->wht_rate);
    }

    public function test_a_rate_above_one_hundred_percent_is_refused(): void
    {
        [, $admin] = $this->world(whtRateBp: null);

        $this->actingAs($admin)
            ->putJson('/api/v1/commission-withdrawal-settings', [
                'min_withdrawal_satang' => null,
                'wht_rate' => 10_001,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('wht_rate');
    }

    public function test_the_agent_is_shown_the_rate_before_they_ask(): void
    {
        // So the portal can show "จะได้รับจริง" beside the amount. The rate,
        // not a net: the amount is not known until they type it.
        [, , $agent] = $this->world(whtRateBp: 300);

        $this->actingAs($agent)
            ->getJson('/api/v1/commission-withdrawals/available')
            ->assertOk()
            ->assertJsonPath('wht_rate', 300);
    }
}
