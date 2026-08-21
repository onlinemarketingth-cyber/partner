<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Section 5 rule 4 (Agent sees only their own commission entries — same
// shape as Client/Referral), BR-4 (markPaid is the one allowed mutation,
// restricted to Company Admin/Super Admin — an Agent marking their own
// commission "paid" would be an obvious self-dealing gap).
class CommissionLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_only_sees_own_commission_entries(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        CommissionLedger::factory()->create(['company_id' => $company->id, 'agent_id' => $agentA->id]);
        CommissionLedger::factory()->create(['company_id' => $company->id, 'agent_id' => $agentB->id]);

        $this->actingAs($agentA)
            ->getJson('/api/v1/commission-ledger')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_agent_cannot_view_a_colleagues_commission_entry(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $entry = CommissionLedger::factory()->create(['company_id' => $company->id, 'agent_id' => $agentB->id]);

        $this->actingAs($agentA)
            ->getJson("/api/v1/commission-ledger/{$entry->id}")
            ->assertForbidden();
    }

    public function test_cross_tenant_commission_entry_access_is_404(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $foreignEntry = CommissionLedger::factory()->create(['company_id' => $otherCompany->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/commission-ledger/{$foreignEntry->id}")
            ->assertNotFound();
    }

    public function test_company_admin_sees_all_commission_entries_in_their_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        CommissionLedger::factory()->count(2)->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-ledger')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_agent_cannot_mark_their_own_commission_as_paid(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $entry = CommissionLedger::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->postJson("/api/v1/commission-ledger/{$entry->id}/mark-paid")
            ->assertForbidden();

        $this->assertSame('pending', $entry->fresh()->payment_status->value);
    }

    public function test_marking_a_commission_paid_records_who_did_it(): void
    {
        /*
         * SECURITY AUDIT 2026-08-21 (V13) — this was the ONE money-moving
         * action in the application and the one action nobody recorded.
         *
         * audit_logs' own migration says the table exists "for anything
         * affecting money, commission, status, certification, or
         * permissions". Role changes, bank-account edits and national-id
         * edits were all audited. The moment a commission stops being owed
         * and starts being paid was not, and the only trace left behind was
         * paid_at — which says when, and never who.
         */
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $entry = CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'amount_satang' => 26700,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/commission-ledger/{$entry->id}/mark-paid")
            ->assertOk();

        $log = AuditLog::where('action', 'commission_ledger.marked_paid')->sole();

        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame($company->id, $log->company_id);
        $this->assertSame($entry->id, $log->auditable_id);
        // The amount is recorded alongside the status on purpose: an audit
        // entry that forces the reader to join another table to learn what
        // was actually paid is an audit entry people stop reading.
        $this->assertSame(26700, $log->new_values['amount_satang']);
        $this->assertSame($agent->id, $log->new_values['agent_user_id']);
    }

    public function test_company_admin_can_mark_a_commission_as_paid(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $entry = CommissionLedger::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/commission-ledger/{$entry->id}/mark-paid")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        $entry->refresh();
        $this->assertSame('paid', $entry->payment_status->value);
        $this->assertNotNull($entry->paid_at);
    }

    // --- TASK-046: ?agent_id= drill-down filter (Admin only) ---

    public function test_company_admin_can_filter_commission_entries_by_agent_id(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        CommissionLedger::factory()->create(['company_id' => $company->id, 'agent_id' => $agentA->id]);
        CommissionLedger::factory()->create(['company_id' => $company->id, 'agent_id' => $agentB->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/commission-ledger?agent_id={$agentA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.agent.id', $agentA->id);
    }

    public function test_agent_id_filter_for_a_foreign_company_agent_returns_no_entries(): void
    {
        // BR-6 — TenantScope already narrows the Admin's query to their
        // own company_id BEFORE the agent_id filter is applied, so a
        // foreign-company agent_id can never leak another company's row:
        // it just naturally matches nothing (no explicit cross-company
        // guard needed for this filter specifically).
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $foreignAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);
        CommissionLedger::factory()->create(['company_id' => $otherCompany->id, 'agent_id' => $foreignAgent->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/commission-ledger?agent_id={$foreignAgent->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_agent_role_ignores_the_agent_id_query_param_and_only_ever_sees_their_own_entries(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        CommissionLedger::factory()->create(['company_id' => $company->id, 'agent_id' => $agentA->id]);
        CommissionLedger::factory()->create(['company_id' => $company->id, 'agent_id' => $agentB->id]);

        // agentA tries to view agentB's entries by tampering with the
        // query string — the unconditional self-filter must win.
        $this->actingAs($agentA)
            ->getJson("/api/v1/commission-ledger?agent_id={$agentB->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.agent.id', $agentA->id);
    }

    public function test_agent_id_filter_combines_with_date_range_and_payment_status(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // created_at is set AT CREATION, not back-dated with a second
        // save(). Since the 2026-08-21 audit (V12) a commission_ledger row
        // refuses any post-creation change outside payment_status/paid_at,
        // and that guard is doing its job here: back-dating a ledger entry
        // after the fact is precisely the kind of quiet rewrite BR-4 exists
        // to forbid. A fixture wanting an older row can simply create an
        // older row.
        $inRangePaid = CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'payment_status' => PaymentStatus::Paid,
            'created_at' => now()->subDays(2),
        ]);

        $inRangePending = CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'payment_status' => PaymentStatus::Pending,
            'created_at' => now()->subDays(2),
        ]);

        $outOfRangePaid = CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'payment_status' => PaymentStatus::Paid,
            'created_at' => now()->subDays(30),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-ledger?agent_id='.$agent->id.
                '&date_from='.now()->subDays(5)->toDateString().
                '&date_to='.now()->toDateString().
                '&payment_status=paid')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inRangePaid->id);
    }

    public function test_commission_ledger_response_includes_earned_via_and_override_source_agent(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $downlineAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $manager = User::factory()->agent()->create(['company_id' => $company->id]);

        $entry = CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $manager->id,
            'earned_via' => CommissionEarnedVia::Override,
            'override_source_agent_id' => $downlineAgent->id,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/commission-ledger?agent_id={$manager->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $entry->id)
            ->assertJsonPath('data.0.earned_via', 'override')
            ->assertJsonPath('data.0.override_source_agent.id', $downlineAgent->id)
            ->assertJsonPath('data.0.override_source_agent.name', $downlineAgent->name);
    }
}
