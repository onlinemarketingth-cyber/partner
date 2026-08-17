<?php

namespace Tests\Feature\Commission;

use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-043 §3 (base endpoint) + TASK-044 §2/§3 (date_from/date_to/
// payment_status filters + CSV export). Covers: filter correctness,
// backward-compat with the pre-TASK-044 unfiltered shape, tenant
// isolation staying intact with the new filters active, Super Admin's
// ?company_id= narrowing (and a Company Admin's attempt to spoof it),
// the export's deliberate real-bank-data + missing_bank_info contract
// (task spec decision #3 — never blocks on missing data), and (human
// request 2026-07-23) the export being forced pending-only — an agent
// with zero pending balance is dropped from the file entirely and any
// payment_status query param on export is silently ignored.
class AgentCommissionSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(User $agent, array $overrides = []): CommissionLedger
    {
        $entry = CommissionLedger::factory()->create(array_merge([
            'company_id' => $agent->company_id,
            'agent_id' => $agent->id,
        ], $overrides));

        if (array_key_exists('created_at', $overrides)) {
            // created_at is deliberately not in CommissionLedger::$fillable
            // (BR-4 immutability — nothing about this row is meant to be
            // mass-assignable after the fact), so a factory ->create() with
            // 'created_at' silently drops it. forceFill()->save() bypasses
            // the guard the same way a raw DB timestamp backfill would.
            $entry->forceFill(['created_at' => $overrides['created_at']])->save();
        }

        return $entry->fresh();
    }

    // --- Access gate (unchanged by TASK-044) ---

    public function test_agent_role_cannot_view_the_summary(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)->getJson('/api/v1/agent-commission-summary')->assertForbidden();
    }

    // --- date_from / date_to ---

    public function test_date_range_filter_narrows_which_ledger_rows_are_summed(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->ledger($agent, ['amount_satang' => 10000, 'created_at' => now()->subDays(10)]); // out of range
        $this->ledger($agent, ['amount_satang' => 20000, 'created_at' => now()->subDays(5)]);   // in range
        $this->ledger($agent, ['amount_satang' => 30000, 'created_at' => now()]);                // in range

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/agent-commission-summary?'.http_build_query([
                'date_from' => now()->subDays(6)->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('agent_id', $agent->id);
        $this->assertSame(2, $row['entry_count']);
        $this->assertSame(50000, $row['total_paid_satang'] + $row['total_pending_satang']);
    }

    public function test_date_to_alone_excludes_rows_after_it(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->ledger($agent, ['amount_satang' => 10000, 'created_at' => now()->subDays(3)]);
        $this->ledger($agent, ['amount_satang' => 20000, 'created_at' => now()]);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/agent-commission-summary?date_to='.now()->subDay()->toDateString())
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('agent_id', $agent->id);
        $this->assertSame(1, $row['entry_count']);
        $this->assertSame(10000, $row['total_paid_satang'] + $row['total_pending_satang']);
    }

    // --- payment_status ---

    public function test_payment_status_paid_only_sums_paid_rows(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->ledger($agent, ['amount_satang' => 10000, 'payment_status' => 'paid', 'paid_at' => now()]);
        $this->ledger($agent, ['amount_satang' => 5000, 'payment_status' => 'paid', 'paid_at' => now()]);
        $this->ledger($agent, ['amount_satang' => 7000, 'payment_status' => 'pending']);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/agent-commission-summary?payment_status=paid')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('agent_id', $agent->id);
        $this->assertSame(15000, $row['total_paid_satang']);
        // TASK-179 §3.7 (F-10) — the excluded bucket is NULL ("not
        // measured"), never 0. A 0 here rendered as "รอจ่ายรวม 0 บาท",
        // indistinguishable from "we owe our agents nothing" — while the
        // agent above is in fact still owed 7,000 satang.
        $this->assertNull($row['total_pending_satang']);
        $this->assertSame(2, $row['entry_count']);
    }

    public function test_payment_status_pending_only_sums_pending_rows(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->ledger($agent, ['amount_satang' => 10000, 'payment_status' => 'paid', 'paid_at' => now()]);
        $this->ledger($agent, ['amount_satang' => 7000, 'payment_status' => 'pending']);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/agent-commission-summary?payment_status=pending')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('agent_id', $agent->id);
        // TASK-179 §3.7 — mirror image of the test above.
        $this->assertNull($row['total_paid_satang']);
        $this->assertSame(7000, $row['total_pending_satang']);
        $this->assertSame(1, $row['entry_count']);
    }

    public function test_invalid_payment_status_value_is_rejected(): void
    {
        $admin = User::factory()->companyAdmin()->create();

        $this->actingAs($admin)
            ->getJson('/api/v1/agent-commission-summary?payment_status=processing')
            ->assertUnprocessable();
    }

    // --- Backward compatibility (no filters) ---

    public function test_omitting_all_filters_matches_pre_task044_unfiltered_behavior(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->ledger($agent, ['amount_satang' => 7000, 'payment_status' => 'paid', 'paid_at' => now()]);
        $this->ledger($agent, ['amount_satang' => 3000, 'payment_status' => 'pending']);

        $response = $this->actingAs($admin)->getJson('/api/v1/agent-commission-summary')->assertOk();

        $row = collect($response->json('data'))->firstWhere('agent_id', $agent->id);
        $this->assertSame(7000, $row['total_paid_satang']);
        $this->assertSame(3000, $row['total_pending_satang']);
        $this->assertSame(2, $row['entry_count']);
    }

    // --- Tenant isolation with filters active simultaneously ---

    public function test_company_admins_filtered_summary_never_includes_another_companys_rows(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $ownAgent = User::factory()->agent()->create(['company_id' => $ownCompany->id]);
        $foreignAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);

        // Same date range, same payment_status — the only thing that
        // should separate them is tenant scoping, exercised WITH the new
        // filters simultaneously active (not filters-off).
        $this->ledger($ownAgent, ['amount_satang' => 10000, 'payment_status' => 'paid', 'paid_at' => now(), 'created_at' => now()]);
        $this->ledger($foreignAgent, ['amount_satang' => 99999, 'payment_status' => 'paid', 'paid_at' => now(), 'created_at' => now()]);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/agent-commission-summary?'.http_build_query([
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->toDateString(),
                'payment_status' => 'paid',
            ]))
            ->assertOk();

        $agentIds = collect($response->json('data'))->pluck('agent_id');
        $this->assertTrue($agentIds->contains($ownAgent->id));
        $this->assertFalse($agentIds->contains($foreignAgent->id));
    }

    // --- Super Admin ?company_id= narrowing ---

    public function test_super_admin_can_narrow_the_index_by_company_id(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $companyB->id]);

        $this->ledger($agentA, ['amount_satang' => 1000]);
        $this->ledger($agentB, ['amount_satang' => 2000]);

        $response = $this->actingAs($superAdmin)
            ->getJson("/api/v1/agent-commission-summary?company_id={$companyA->id}")
            ->assertOk();

        $agentIds = collect($response->json('data'))->pluck('agent_id');
        $this->assertTrue($agentIds->contains($agentA->id));
        $this->assertFalse($agentIds->contains($agentB->id));
    }

    public function test_super_admin_sees_all_companies_when_company_id_is_omitted(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $companyB->id]);

        $this->ledger($agentA, ['amount_satang' => 1000]);
        $this->ledger($agentB, ['amount_satang' => 2000]);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/agent-commission-summary')->assertOk();

        $agentIds = collect($response->json('data'))->pluck('agent_id');
        $this->assertTrue($agentIds->contains($agentA->id));
        $this->assertTrue($agentIds->contains($agentB->id));
    }

    public function test_company_admin_passing_a_foreign_company_id_is_silently_ignored(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $ownAgent = User::factory()->agent()->create(['company_id' => $ownCompany->id]);
        $foreignAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);

        $this->ledger($ownAgent, ['amount_satang' => 1000]);
        $this->ledger($foreignAgent, ['amount_satang' => 2000]);

        // AgentCommissionSummaryController only reads $request->company_id
        // when isSuperAdmin() — a Company Admin's own value is never even
        // looked at, so passing another company's id must NOT grant access
        // to it; the result must stay scoped to the admin's own company.
        $response = $this->actingAs($admin)
            ->getJson("/api/v1/agent-commission-summary?company_id={$otherCompany->id}")
            ->assertOk();

        $agentIds = collect($response->json('data'))->pluck('agent_id');
        $this->assertTrue($agentIds->contains($ownAgent->id));
        $this->assertFalse($agentIds->contains($foreignAgent->id));
    }

    // --- CSV export ---

    public function test_company_admin_gets_a_csv_export(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->ledger($agent, ['amount_satang' => 1000]);

        $response = $this->actingAs($admin)->get('/api/v1/agent-commission-summary/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_agent_role_is_forbidden_from_exporting(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)->get('/api/v1/agent-commission-summary/export')->assertForbidden();
    }

    public function test_export_contains_the_real_unmasked_bank_account_number(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'first_name' => 'Somchai',
            'last_name' => 'Jaidee',
            'bank_name' => 'Bangkok Bank',
            'bank_account_number' => '1234567890',
            'bank_account_holder_name' => 'Somchai Jaidee',
        ]);
        $this->ledger($agent, ['amount_satang' => 1000, 'payment_status' => 'pending']);

        $response = $this->actingAs($admin)->get('/api/v1/agent-commission-summary/export')->assertOk();

        // Deliberate per task spec decision — the export is the one place
        // in this codebase that legitimately needs the real number (Admin
        // must have it to run an actual bank transfer), unlike every
        // UserResource-backed response which masks it.
        $this->assertStringContainsString('1234567890', $response->streamedContent());
    }

    public function test_export_still_includes_a_row_for_an_agent_with_no_bank_info_and_flags_it(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'first_name' => 'NoBank',
            'last_name' => 'Info',
            'bank_name' => null,
            'bank_account_number' => null,
            'bank_account_holder_name' => null,
        ]);
        $this->ledger($agent, ['amount_satang' => 500, 'payment_status' => 'pending']);

        $response = $this->actingAs($admin)->get('/api/v1/agent-commission-summary/export')->assertOk();

        $content = $response->streamedContent();

        // Never silently blocked/dropped (task spec decision #3) — the row
        // for this agent must still be present in the file.
        $this->assertStringContainsString('NoBank Info', $content);

        // The "missing bank info" indicator ('ใช่' = Thai for "yes") must
        // appear on that agent's own line, not just somewhere in the file.
        $lines = collect(explode("\n", $content));
        $agentLine = $lines->first(fn ($line) => str_contains($line, 'NoBank Info'));
        $this->assertNotNull($agentLine);
        $this->assertStringContainsString('ใช่', $agentLine);
    }

    public function test_super_admin_can_narrow_the_export_by_company_id(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $agentA = User::factory()->agent()->create([
            'company_id' => $companyA->id,
            'first_name' => 'AgentA', 'last_name' => 'Company',
        ]);
        $agentB = User::factory()->agent()->create([
            'company_id' => $companyB->id,
            'first_name' => 'AgentB', 'last_name' => 'Company',
        ]);
        $this->ledger($agentA, ['amount_satang' => 1000]);
        $this->ledger($agentB, ['amount_satang' => 2000]);

        $response = $this->actingAs($superAdmin)
            ->get("/api/v1/agent-commission-summary/export?company_id={$companyA->id}")
            ->assertOk();

        $content = $response->streamedContent();
        $this->assertStringContainsString('AgentA Company', $content);
        $this->assertStringNotContainsString('AgentB Company', $content);
    }

    public function test_company_admin_passing_a_foreign_company_id_on_export_is_ignored(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $ownAgent = User::factory()->agent()->create([
            'company_id' => $ownCompany->id,
            'first_name' => 'Own', 'last_name' => 'Agent',
        ]);
        $foreignAgent = User::factory()->agent()->create([
            'company_id' => $otherCompany->id,
            'first_name' => 'Foreign', 'last_name' => 'Agent',
            'bank_account_number' => '4242424242',
        ]);
        $this->ledger($ownAgent, ['amount_satang' => 1000]);
        $this->ledger($foreignAgent, ['amount_satang' => 2000]);

        $response = $this->actingAs($admin)
            ->get("/api/v1/agent-commission-summary/export?company_id={$otherCompany->id}")
            ->assertOk();

        $content = $response->streamedContent();
        $this->assertStringContainsString('Own Agent', $content);
        $this->assertStringNotContainsString('Foreign Agent', $content);
        $this->assertStringNotContainsString('4242424242', $content);
    }

    // --- Export is pending-only (human request, 2026-07-23: "export ส่ง
    // csv ส่งไปเฉพาะยอดที่ต้องจ่าย") ---

    public function test_export_excludes_an_agent_whose_entries_are_all_already_paid(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $paidOnlyAgent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'first_name' => 'AllPaid', 'last_name' => 'Agent',
        ]);
        $pendingAgent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'first_name' => 'StillOwed', 'last_name' => 'Agent',
        ]);
        $this->ledger($paidOnlyAgent, ['amount_satang' => 10000, 'payment_status' => 'paid', 'paid_at' => now()]);
        $this->ledger($pendingAgent, ['amount_satang' => 5000, 'payment_status' => 'pending']);

        $response = $this->actingAs($admin)->get('/api/v1/agent-commission-summary/export')->assertOk();
        $content = $response->streamedContent();

        // A payout file has nothing to do for an agent with zero pending
        // balance — that agent must not appear in the file at all, not
        // merely show a zero amount.
        $this->assertStringNotContainsString('AllPaid Agent', $content);
        $this->assertStringContainsString('StillOwed Agent', $content);
    }

    public function test_export_amount_reflects_only_the_pending_portion_of_a_mixed_agent(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'first_name' => 'Mixed', 'last_name' => 'Agent',
        ]);
        $this->ledger($agent, ['amount_satang' => 10000, 'payment_status' => 'paid', 'paid_at' => now()]);
        $this->ledger($agent, ['amount_satang' => 3000, 'payment_status' => 'pending']);

        $response = $this->actingAs($admin)->get('/api/v1/agent-commission-summary/export')->assertOk();
        $content = $response->streamedContent();

        $lines = collect(explode("\n", $content));
        $agentLine = $lines->first(fn ($line) => str_contains($line, 'Mixed Agent'));
        $this->assertNotNull($agentLine);
        // Only the 3000-satang (30.00 baht) pending amount, never the
        // already-paid 10000 satang (100.00 baht) — and no separate "paid"
        // column exists any more for it to leak into either.
        $this->assertStringContainsString('30.00', $agentLine);
        $this->assertStringNotContainsString('100.00', $agentLine);
    }

    public function test_export_ignores_a_payment_status_query_param(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id,
            'first_name' => 'Ignores', 'last_name' => 'Filter',
        ]);
        $this->ledger($agent, ['amount_satang' => 4000, 'payment_status' => 'pending']);

        // Unlike index(), export() no longer accepts payment_status at all
        // — passing ?payment_status=paid must neither error (it's simply
        // not in the validated set) nor change the pending-only result.
        $response = $this->actingAs($admin)
            ->get('/api/v1/agent-commission-summary/export?payment_status=paid')
            ->assertOk();

        $this->assertStringContainsString('Ignores Filter', $response->streamedContent());
    }
}
