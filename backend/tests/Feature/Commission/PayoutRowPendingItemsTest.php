<?php

namespace Tests\Feature\Commission;

use App\Enums\PaymentStatus;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-17 — WHAT THE UNPAID BALANCE IS MADE OF, ON THE ROW.
 *
 * Owner: "ให้แสดงสินค้าและราคาขาย ในช่องแรกเลย".
 *
 * The payout row said "1 รายการ" and nothing else. Somebody about to transfer
 * money to a person could see the amount and not what it was for without
 * opening the drill-down — one press per payee, on the screen whose whole job
 * is to decide a payout round at a glance.
 *
 * Three properties are worth pinning, and all three are about honesty rather
 * than about the feature working:
 *
 *   · PENDING ONLY. The list sits beside a pending figure. A settled sale in it
 *     would be a product the amount next to it does not include.
 *   · CAPPED, AND SAID SO. `entry_count` stays the true total, so a screen
 *     showing three of eleven can say so instead of implying it has them all.
 *   · SCOPED LIKE EVERYTHING ELSE. A second query reading the ledger is a
 *     second chance to forget BR-6.
 */
class PayoutRowPendingItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_row_names_the_products_behind_the_balance(): void
    {
        [$company, $admin, $agent] = $this->world();
        $this->sale($company, $agent, product: 'GENESENN Health Tracker V5', price: 890000, commission: 17800);

        $row = $this->summaryRow($admin, $agent);

        $this->assertCount(1, $row['pending_items']);
        $this->assertSame('GENESENN Health Tracker V5', $row['pending_items'][0]['product_name']);
        $this->assertSame(890000, $row['pending_items'][0]['sale_price_satang']);
        $this->assertSame(17800, $row['pending_items'][0]['amount_satang']);
    }

    public function test_a_settled_sale_is_not_in_a_list_that_sits_beside_an_unpaid_figure(): void
    {
        /*
         * THE ONE THAT MATTERS. The list is printed next to "ยอดค้างจ่าย". A
         * product in it that has already been paid for makes the row read as if
         * that amount covered it.
         */
        [$company, $admin, $agent] = $this->world();
        $this->sale($company, $agent, product: 'ยังไม่ได้จ่าย', price: 890000, commission: 17800);
        $this->sale($company, $agent, product: 'จ่ายไปแล้ว', price: 2990000, commission: 149500, status: PaymentStatus::Paid);

        $names = array_column($this->summaryRow($admin, $agent)['pending_items'], 'product_name');

        $this->assertSame(['ยังไม่ได้จ่าย'], $names);
    }

    public function test_it_caps_the_list_but_never_the_count_beside_it(): void
    {
        // Forty product names per row, on every row, for a screen with room for
        // two lines. The cap is the screen's; entry_count stays the truth.
        [$company, $admin, $agent] = $this->world();

        for ($i = 1; $i <= 6; $i++) {
            $this->sale($company, $agent, product: "สินค้า {$i}", price: 100000, commission: 2000);
        }

        $row = $this->summaryRow($admin, $agent);

        $this->assertCount(3, $row['pending_items']);
        $this->assertSame(6, $row['entry_count']);
    }

    public function test_it_shows_the_newest_sales_rather_than_the_biggest(): void
    {
        /*
         * An admin scanning this row is checking that the balance looks like
         * recent activity they recognise. Ordering by amount would hide today's
         * sale behind a large one from March.
         */
        [$company, $admin, $agent] = $this->world();
        $this->sale($company, $agent, product: 'ก้อนใหญ่เมื่อนานมาแล้ว', price: 9990000, commission: 499500);
        $this->sale($company, $agent, product: 'ขายเมื่อวาน', price: 100000, commission: 2000);
        $this->sale($company, $agent, product: 'ขายวันนี้', price: 100000, commission: 2000);
        $this->sale($company, $agent, product: 'ขายเมื่อกี้', price: 100000, commission: 2000);

        $names = array_column($this->summaryRow($admin, $agent)['pending_items'], 'product_name');

        $this->assertSame(['ขายเมื่อกี้', 'ขายวันนี้', 'ขายเมื่อวาน'], $names);
    }

    public function test_a_sale_with_no_recorded_price_says_so_rather_than_inventing_one(): void
    {
        // Rows written before the price snapshot existed have none. BR-7: a
        // business value nobody recorded is not one this app may make up.
        [$company, $admin, $agent] = $this->world();
        $this->sale($company, $agent, product: 'ของเก่า', price: null, commission: 17800);

        $this->assertNull($this->summaryRow($admin, $agent)['pending_items'][0]['sale_price_satang']);
    }

    public function test_another_companys_sales_are_not_on_the_row(): void
    {
        /*
         * BR-6. A second query reading commission_ledger is a second chance to
         * forget the tenant scope — and this one returns product names, which
         * is a competitor's catalogue.
         */
        [$company, $admin, $agent] = $this->world();
        [$other, , $theirAgent] = $this->world();

        $this->sale($company, $agent, product: 'ของเรา', price: 100000, commission: 2000);
        $this->sale($other, $theirAgent, product: 'ของบริษัทอื่น', price: 100000, commission: 2000);

        $names = collect($this->actingAs($admin)->getJson('/api/v1/agent-commission-summary')->json('data'))
            ->flatMap(fn (array $row) => array_column($row['pending_items'], 'product_name'))
            ->all();

        $this->assertContains('ของเรา', $names);
        $this->assertNotContains('ของบริษัทอื่น', $names);
    }

    public function test_a_payee_with_nothing_unpaid_carries_an_empty_list_rather_than_a_missing_key(): void
    {
        // A missing key makes the screen choose between rendering nothing and
        // inventing something; an empty list is the true answer and the server
        // is the one that knows it.
        [$company, $admin, $agent] = $this->world();
        $this->sale($company, $agent, product: 'จ่ายแล้ว', price: 100000, commission: 2000, status: PaymentStatus::Paid);

        $this->assertSame([], $this->summaryRow($admin, $agent)['pending_items']);
    }

    /** @return array{0: Company, 1: User, 2: User} */
    private function world(): array
    {
        $company = Company::factory()->create();

        return [
            $company,
            User::factory()->companyAdmin()->create(['company_id' => $company->id]),
            User::factory()->agent()->create(['company_id' => $company->id]),
        ];
    }

    private function sale(
        Company $company,
        User $agent,
        string $product,
        ?int $price,
        int $commission,
        PaymentStatus $status = PaymentStatus::Pending,
    ): void {
        CommissionLedger::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => Product::factory()->create([
                'company_id' => $company->id,
                'name' => $product,
            ])->id,
            'rate_type_applied' => 'percentage',
            'rate_applied' => 200,
            'amount_satang' => $commission,
            'sale_price_satang_at_time' => $price,
            'payment_status' => $status,
            'paid_at' => $status === PaymentStatus::Paid ? now() : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function summaryRow(User $admin, User $agent): array
    {
        return collect($this->actingAs($admin)
            ->getJson('/api/v1/agent-commission-summary')
            ->assertOk()
            ->json('data'))
            ->firstWhere('agent_id', $agent->id);
    }
}
