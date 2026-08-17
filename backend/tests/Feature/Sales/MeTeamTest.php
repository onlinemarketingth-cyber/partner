<?php

namespace Tests\Feature\Sales;

use App\Enums\CommissionEarnedVia;
use App\Enums\PaymentStatus;
use App\Enums\PipelineStage;
use App\Enums\TeamVisibilityLevel;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionSplitSetting;
use App\Models\Company;
use App\Models\Order;
use App\Models\Referral;
use App\Models\TeamVisibilitySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-107 / ADR-024 — the read-only /me/team endpoints.
 *
 * Acceptance-criterion map (TASK-106 sprint doc, TASK-107 section):
 *   - every endpoint is GET, no write verb routable
 *       → test_no_write_verb_is_routable_under_me_team
 *   - agent with no reports: is_leader false, empty nodes, HTTP 200
 *       → test_an_agent_with_no_reports_gets_an_empty_team_and_200
 *   - ?parent_id outside my subtree → 404 (the primary IDOR surface)
 *       → test_parent_id_belonging_to_a_sibling_leader_is_404
 *         test_parent_id_pointing_upward_at_my_own_manager_is_404
 *         test_parent_id_from_another_company_is_404
 *         test_parent_id_pointing_at_myself_is_404
 *   - same for {user} on the drill-down
 *       → test_drill_down_on_a_sibling_leaders_agent_is_404
 *         test_drill_down_on_another_companys_agent_is_404
 *   - names level: phone/national_id/document fields are ABSENT keys
 *       → test_names_level_returns_only_name_and_stage_as_an_exact_key_set
 *   - full_file: an audit row exists after the call
 *       → test_full_file_returns_the_client_file_and_writes_exactly_one_audit_row
 *   - counts_only: drill-down 403 (and it is the fail-closed default)
 *       → test_counts_only_forbids_the_drill_down
 *         test_an_unconfigured_company_fails_closed_to_counts_only
 *   - money fields are integers, no float anywhere
 *       → test_money_is_read_as_integer_satang_from_its_own_source
 *         test_no_float_appears_anywhere_in_the_team_payload
 *   - changing the company's level changes the payload without a deploy
 *       → test_changing_the_company_level_changes_the_payload
 *   - /me/home carries direct_reports_count
 *       → test_me_home_exposes_direct_reports_count
 *
 * TASK-111 additions (ag-qa findings D1-D4, D8):
 *   - D1 is_enabled = false is a real kill switch, at all three touchpoints
 *       → test_a_disabled_setting_forbids_the_drill_down
 *         test_a_disabled_setting_empties_the_team_overview
 *         test_a_disabled_setting_zeroes_direct_reports_count_on_home
 *   - D2 full_file exact key set + the decrypted national_id stays out
 *       → test_full_file_exposes_the_file_but_never_the_decrypted_national_id
 *   - D3 full_file must not name agents outside the caller's subtree
 *       → test_full_file_hides_agent_identities_from_outside_the_subtree
 *   - D4 pending ledger rows excluded from all three satang figures
 *       → test_pending_ledger_rows_are_excluded_from_every_satang_figure
 *   - D8 a leader cannot markPaid a subordinate's commission
 *       → test_a_leader_cannot_mark_a_subordinates_commission_as_paid
 *
 * NOTE (Guardrail 4): these tests were written without a PHP runtime in the
 * sandbox. They have NOT been executed here — the human runs `php artisan
 * test`. No result is claimed.
 */
class MeTeamTest extends TestCase
{
    use RefreshDatabase;

    private function level(Company $company, TeamVisibilityLevel $level, bool $enabled = true): void
    {
        // withoutGlobalScopes(): this helper is sometimes called after
        // actingAs(), and TenantScope would otherwise narrow the lookup by
        // the currently authenticated user — irrelevant noise for a test
        // fixture that always names its company explicitly. Same idiom
        // TeamVisibilitySettingService::upsert() uses.
        TeamVisibilitySetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id],
            ['client_visibility_level' => $level->value, 'is_enabled' => $enabled],
        );
    }

    /**
     * A closed (Complete Payment) deal for $agent: the customer's PAID ORDER
     * plus the agent's paid DIRECT commission row — the shape every money
     * assertion below relies on.
     *
     * TASK-179 (D1/D2): the paid order is what `sales_satang` is now read
     * from (money the CUSTOMER paid), and the ledger row is what
     * `commission_satang` is read from (money the company DISBURSED). Both
     * rows are created here because a real closed sale has both; before
     * TASK-179 the sale figure was taken off the ledger's
     * `sale_price_satang_at_time`, which is the source D2 rejected by name.
     *
     * @return array{client: Client, referral: Referral}
     */
    private function closedSale(Company $company, User $agent, int $saleSatang, int $commissionSatang): array
    {
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'referring_agent_id' => $agent->id,
            'phone' => '0812345678',
            'national_id' => '1234567890123',
            'health_notes' => 'แพ้ยาเพนิซิลลิน',
        ]);

        $referral = Referral::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'current_stage' => PipelineStage::CompletePayment,
        ]);

        // The CUSTOMER's money (D1) — what sales_satang is read from.
        Order::factory()->paid()->create([
            'company_id' => $company->id,
            'referral_id' => $referral->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'product_id' => $referral->product_id,
            'amount_satang' => $saleSatang,
            'paid_at' => now(),
        ]);

        // The company's DISBURSEMENT to the agent — what commission_satang
        // is read from (BR-4: read, never recomputed).
        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'referral_id' => $referral->id,
            'earned_via' => CommissionEarnedVia::Direct,
            'payment_status' => PaymentStatus::Paid,
            'amount_satang' => $commissionSatang,
            // DELIBERATELY NOT $saleSatang. This column is the source D2
            // rejected by name, and every sales assertion in this file would
            // pass against it if the two agreed. Diverging them makes those
            // assertions discriminate: an implementation that reads the
            // ledger snapshot reports this number and fails.
            'sale_price_satang_at_time' => $saleSatang + 500000,
            'paid_at' => now(),
        ]);

        return ['client' => $client, 'referral' => $referral];
    }

    // ---------------------------------------------------------------
    // Shape + rollup
    // ---------------------------------------------------------------

    public function test_a_leader_sees_their_direct_reports_with_per_node_counts(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $grandchild = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $child->id]);

        $this->closedSale($company, $child, 890000, 26700);

        $data = $this->actingAs($leader)->getJson('/api/v1/me/team')->assertOk()->json('data');

        $this->assertTrue($data['is_leader']);
        $this->assertSame(TeamVisibilityLevel::CountsOnly->value, $data['visibility_level']);
        $this->assertNull($data['parent_id']);

        // Only the DIRECT level is listed; the grandchild is behind an expand.
        $this->assertCount(1, $data['nodes']);
        $node = $data['nodes'][0];
        $this->assertSame($child->id, $node['agent_id']);
        $this->assertTrue($node['has_children']);
        $this->assertSame(1, $node['client_count']);
        $this->assertSame(1, $node['total_deals']);
        $this->assertSame(1, $node['closed_deals']);
        $this->assertSame(1, $node['deals_by_stage'][PipelineStage::CompletePayment->value]);
        // EVERY stage in the vocabulary is always present, zeros included.
        //
        // Was hardcoded to 5 until ADR-026 (2026-08-08) added the three
        // post-sale stages (delivery / service_appointment / follow_up),
        // taking the enum to 8. The count is derived now, not written down:
        // this assertion is about "the rollup emits a bucket per stage", and
        // pinning the literal made a deliberate vocabulary change look like a
        // regression. A stage added tomorrow should not fail this test either.
        $this->assertCount(count(PipelineStage::cases()), $node['deals_by_stage']);

        $this->assertNotContains($grandchild->id, array_column($data['nodes'], 'agent_id'));
    }

    // ADR-024 §3 — the header KPIs must reflect the ENTIRE chain, not just
    // the level currently rendered, so a leader sees the true total without
    // expanding every node.
    public function test_totals_roll_up_the_whole_subtree_not_just_the_visible_level(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $grandchild = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $child->id]);

        $this->closedSale($company, $child, 890000, 26700);
        $this->closedSale($company, $grandchild, 990000, 29700);

        $data = $this->actingAs($leader)->getJson('/api/v1/me/team')->assertOk()->json('data');

        $this->assertSame(2, $data['totals']['member_count']);
        $this->assertSame(2, $data['totals']['client_count']);
        $this->assertSame(2, $data['totals']['total_deals']);
        $this->assertSame(2, $data['totals']['closed_deals']);
        $this->assertSame(890000 + 990000, $data['totals']['sales_satang']);
        $this->assertSame(26700 + 29700, $data['totals']['commission_satang']);

        // ...while nodes[] still shows only the direct level.
        $this->assertCount(1, $data['nodes']);
    }

    public function test_parent_id_expands_that_nodes_children(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $grandchild = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $child->id]);

        $data = $this->actingAs($leader)
            ->getJson('/api/v1/me/team?parent_id='.$child->id)
            ->assertOk()
            ->json('data');

        $this->assertSame($child->id, $data['parent_id']);
        $this->assertSame([$grandchild->id], array_column($data['nodes'], 'agent_id'));
        $this->assertFalse($data['nodes'][0]['has_children']);
        // Totals are subtree-wide regardless of which level is expanded.
        $this->assertSame(2, $data['totals']['member_count']);
    }

    public function test_an_agent_with_no_reports_gets_an_empty_team_and_200(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $loner = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        // Data belonging to someone else in the same company — must not leak.
        $this->closedSale($company, $leader, 890000, 26700);

        $data = $this->actingAs($loner)->getJson('/api/v1/me/team')->assertOk()->json('data');

        $this->assertFalse($data['is_leader']);
        $this->assertSame([], $data['nodes']);
        $this->assertSame(0, $data['totals']['member_count']);
        $this->assertSame(0, $data['totals']['sales_satang']);
    }

    // ---------------------------------------------------------------
    // IDOR — the whole point of ADR-024
    // ---------------------------------------------------------------

    // TASK-110 case 1 — two leaders in the SAME company.
    public function test_parent_id_belonging_to_a_sibling_leader_is_404(): void
    {
        $company = Company::factory()->create();
        $leaderOne = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leaderOne->id]);

        $leaderTwo = User::factory()->agent()->create(['company_id' => $company->id]);
        $twosChild = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leaderTwo->id]);

        $this->actingAs($leaderOne)
            ->getJson('/api/v1/me/team?parent_id='.$twosChild->id)
            ->assertNotFound();
    }

    // TASK-110 case 2 — a subordinate probing upward at their own manager.
    public function test_parent_id_pointing_upward_at_my_own_manager_is_404(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $child->id]);

        $this->actingAs($child)
            ->getJson('/api/v1/me/team?parent_id='.$leader->id)
            ->assertNotFound();
    }

    public function test_parent_id_pointing_at_myself_is_404(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $this->actingAs($leader)
            ->getJson('/api/v1/me/team?parent_id='.$leader->id)
            ->assertNotFound();
    }

    // TASK-110 case 3 — BR-6 tenant isolation.
    public function test_parent_id_from_another_company_is_404(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $leader = User::factory()->agent()->create(['company_id' => $companyA->id]);
        User::factory()->agent()->create(['company_id' => $companyA->id, 'manager_id' => $leader->id]);

        // Even with an (illegal) cross-tenant manager_id edge pointing at
        // our leader, company B must stay invisible.
        $foreign = User::factory()->agent()->create([
            'company_id' => $companyB->id,
            'manager_id' => $leader->id,
        ]);

        $this->actingAs($leader)
            ->getJson('/api/v1/me/team?parent_id='.$foreign->id)
            ->assertNotFound();

        $data = $this->actingAs($leader)->getJson('/api/v1/me/team')->assertOk()->json('data');
        $this->assertNotContains($foreign->id, array_column($data['nodes'], 'agent_id'));
    }

    public function test_parent_id_that_does_not_exist_is_404(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $this->actingAs($leader)
            ->getJson('/api/v1/me/team?parent_id=999999')
            ->assertNotFound();
    }

    public function test_a_non_integer_parent_id_is_rejected_rather_than_treated_as_root(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $this->actingAs($leader)
            ->getJson('/api/v1/me/team?parent_id=abc')
            ->assertStatus(422);
    }

    public function test_drill_down_on_a_sibling_leaders_agent_is_404(): void
    {
        $company = Company::factory()->create();
        $this->level($company, TeamVisibilityLevel::FullFile);

        $leaderOne = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leaderOne->id]);

        $leaderTwo = User::factory()->agent()->create(['company_id' => $company->id]);
        $twosChild = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leaderTwo->id]);
        $this->closedSale($company, $twosChild, 890000, 26700);

        $this->actingAs($leaderOne)
            ->getJson("/api/v1/me/team/{$twosChild->id}/clients")
            ->assertNotFound();

        // ...and nothing was logged, because nothing was disclosed.
        $this->assertSame(0, AuditLog::where('action', 'team_client_file.view')->count());
    }

    public function test_drill_down_on_another_companys_agent_is_404(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->level($companyA, TeamVisibilityLevel::FullFile);

        $leader = User::factory()->agent()->create(['company_id' => $companyA->id]);
        User::factory()->agent()->create(['company_id' => $companyA->id, 'manager_id' => $leader->id]);

        $foreign = User::factory()->agent()->create(['company_id' => $companyB->id]);
        $this->closedSale($companyB, $foreign, 890000, 26700);

        $this->actingAs($leader)
            ->getJson("/api/v1/me/team/{$foreign->id}/clients")
            ->assertNotFound();
    }

    public function test_drill_down_on_myself_is_404(): void
    {
        // A leader's own clients belong on the existing self-scoped
        // /clients endpoint, not here — /me/team is strictly about people
        // BELOW the caller (DownlineService::isInSubtree is false for self).
        $company = Company::factory()->create();
        $this->level($company, TeamVisibilityLevel::FullFile);

        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $this->actingAs($leader)
            ->getJson("/api/v1/me/team/{$leader->id}/clients")
            ->assertNotFound();
    }

    // ---------------------------------------------------------------
    // PDPA levels (ADR-024 §5) — enforced in the Resource, at the API
    // ---------------------------------------------------------------

    public function test_counts_only_forbids_the_drill_down(): void
    {
        $company = Company::factory()->create();
        $this->level($company, TeamVisibilityLevel::CountsOnly);

        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $this->closedSale($company, $child, 890000, 26700);

        $this->actingAs($leader)
            ->getJson("/api/v1/me/team/{$child->id}/clients")
            ->assertForbidden();
    }

    public function test_an_unconfigured_company_fails_closed_to_counts_only(): void
    {
        $company = Company::factory()->create();
        $this->assertDatabaseMissing('team_visibility_settings', ['company_id' => $company->id]);

        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $this->closedSale($company, $child, 890000, 26700);

        $this->actingAs($leader)
            ->getJson("/api/v1/me/team/{$child->id}/clients")
            ->assertForbidden();
    }

    // ---------------------------------------------------------------
    // TASK-111 (D1) — is_enabled is a REAL kill switch
    //
    // The old single test only checked the drill-down 403, which is why it
    // could not see the bug: with the switch OFF the OVERVIEW still returned
    // every subordinate's name, client counts, pipeline stages and all three
    // satang figures, and /me/home still reported direct_reports_count > 0 so
    // HomeView still rendered the "ทีมของฉัน" menu entry. Split into one test
    // per touchpoint, each asserting the payload rather than the status code
    // alone.
    // ---------------------------------------------------------------

    public function test_a_disabled_setting_forbids_the_drill_down(): void
    {
        $company = Company::factory()->create();
        // Widest level stored, master switch OFF.
        $this->level($company, TeamVisibilityLevel::FullFile, enabled: false);

        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $this->closedSale($company, $child, 890000, 26700);

        $this->actingAs($leader)
            ->getJson("/api/v1/me/team/{$child->id}/clients")
            ->assertForbidden();

        // Nothing was disclosed, so nothing is logged (ADR-024 §8).
        $this->assertSame(0, AuditLog::where('action', 'team_client_file.view')->count());
    }

    public function test_a_disabled_setting_empties_the_team_overview(): void
    {
        $company = Company::factory()->create();
        $this->level($company, TeamVisibilityLevel::FullFile, enabled: false);

        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $grandchild = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $child->id]);
        $sale = $this->closedSale($company, $child, 890000, 26700);
        $this->closedSale($company, $grandchild, 990000, 29700);

        // The leader's own override earnings from the child — the most
        // sensitive of the three satang figures.
        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
            'referral_id' => $sale['referral']->id,
            'override_source_agent_id' => $child->id,
            'earned_via' => CommissionEarnedVia::Override,
            'payment_status' => PaymentStatus::Paid,
            'amount_satang' => 8900,
            'sale_price_satang_at_time' => null,
            'paid_at' => now(),
        ]);

        // 200, not 403: the leader has done nothing wrong and the tenant may
        // re-enable at any time. The shape is exactly the one a non-leader
        // already gets, so the frontend needs no new branch.
        $data = $this->actingAs($leader)->getJson('/api/v1/me/team')->assertOk()->json('data');

        $this->assertFalse($data['is_leader']);
        $this->assertSame([], $data['nodes']);
        $this->assertSame(TeamVisibilityLevel::CountsOnly->value, $data['visibility_level']);

        // EVERY total zeroed — member count, pipeline counts and all three
        // money figures. Asserted exhaustively rather than spot-checked,
        // because the defect was precisely that these kept their real values.
        $this->assertSame(0, $data['totals']['member_count']);
        $this->assertSame(0, $data['totals']['client_count']);
        $this->assertSame(0, $data['totals']['total_deals']);
        $this->assertSame(0, $data['totals']['closed_deals']);
        $this->assertSame(0, $data['totals']['sales_satang']);
        $this->assertSame(0, $data['totals']['commission_satang']);
        $this->assertSame(0, $data['totals']['my_override_satang']);
        foreach ($data['totals']['deals_by_stage'] as $stage => $count) {
            $this->assertSame(0, $count, "stage '{$stage}' must be zeroed when the feature is off");
        }

        // Expanding a node the caller really does own is answered the same
        // way — the feature does not exist for this tenant, so there is no
        // subtree to be inside of and no 403/404 oracle to probe with.
        $expanded = $this->actingAs($leader)
            ->getJson('/api/v1/me/team?parent_id='.$child->id)
            ->assertOk()
            ->json('data');
        $this->assertSame([], $expanded['nodes']);
        $this->assertSame(0, $expanded['totals']['member_count']);

        // ...and the SAME fixture with the switch back ON does show the team.
        // Without this the test above would still pass against a service that
        // simply returned nothing to anyone.
        $this->level($company, TeamVisibilityLevel::FullFile, enabled: true);
        $reEnabled = $this->actingAs($leader)->getJson('/api/v1/me/team')->assertOk()->json('data');

        $this->assertTrue($reEnabled['is_leader']);
        $this->assertSame([$child->id], array_column($reEnabled['nodes'], 'agent_id'));
        $this->assertSame(890000 + 990000, $reEnabled['totals']['sales_satang']);
        $this->assertSame(8900, $reEnabled['totals']['my_override_satang']);
    }

    // ADR-024 §9 — HomeView renders the team menu entry purely from this
    // count, so the count is where the kill switch has to land for the menu
    // to disappear without any frontend change.
    public function test_a_disabled_setting_zeroes_direct_reports_count_on_home(): void
    {
        $company = Company::factory()->create();
        $this->level($company, TeamVisibilityLevel::FullFile, enabled: false);

        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $data = $this->actingAs($leader)->getJson('/api/v1/me/home')->assertOk()->json('data');
        $this->assertSame(0, $data['direct_reports_count']);

        // Same leader, same two reports, switch ON → the entry comes back.
        $this->level($company, TeamVisibilityLevel::FullFile, enabled: true);
        $reEnabled = $this->actingAs($leader)->getJson('/api/v1/me/home')->assertOk()->json('data');
        $this->assertSame(2, $reEnabled['direct_reports_count']);
    }

    // The headline PDPA assertion: at `names`, the forbidden fields must be
    // ABSENT KEYS — not null, not empty strings.
    public function test_names_level_returns_only_name_and_stage_as_an_exact_key_set(): void
    {
        $company = Company::factory()->create();
        $this->level($company, TeamVisibilityLevel::Names);

        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $sale = $this->closedSale($company, $child, 890000, 26700);

        $response = $this->actingAs($leader)
            ->getJson("/api/v1/me/team/{$child->id}/clients")
            ->assertOk();

        $body = $response->json();
        $this->assertSame(TeamVisibilityLevel::Names->value, $body['meta']['visibility_level']);
        $this->assertCount(1, $body['data']);

        $client = $body['data'][0];
        $keys = array_keys($client);
        sort($keys);
        $this->assertSame(['current_stage', 'id', 'name'], $keys);

        $this->assertSame($sale['client']->id, $client['id']);
        $this->assertSame($sale['client']->name, $client['name']);
        $this->assertSame(PipelineStage::CompletePayment->value, $client['current_stage']['key']);

        // Explicit belt-and-braces on the fields PDPA cares about most.
        foreach (['phone', 'email', 'national_id', 'national_id_masked', 'address', 'province',
            'date_of_birth', 'occupation', 'health_notes', 'consent_given_at', 'documents',
            'referrals', 'lead_source'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $client, "'{$forbidden}' must be absent at the names level");
        }

        // ADR-024 §8 — name-level views are deliberately NOT logged per view.
        $this->assertSame(0, AuditLog::where('action', 'team_client_file.view')->count());
    }

    public function test_names_level_shows_the_furthest_advanced_stage_for_that_subordinate(): void
    {
        $company = Company::factory()->create();
        $this->level($company, TeamVisibilityLevel::Names);

        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $client = Client::factory()->create([
            'company_id' => $company->id,
            'referring_agent_id' => $child->id,
        ]);
        Referral::factory()->create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $child->id,
            'current_stage' => PipelineStage::CompleteRegistered,
        ]);
        Referral::factory()->create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $child->id,
            'current_stage' => PipelineStage::Finish1stDoctorMeeting,
        ]);

        $body = $this->actingAs($leader)
            ->getJson("/api/v1/me/team/{$child->id}/clients")
            ->assertOk()
            ->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame(
            PipelineStage::Finish1stDoctorMeeting->value,
            $body['data'][0]['current_stage']['key'],
        );
    }

    public function test_full_file_returns_the_client_file_and_writes_exactly_one_audit_row(): void
    {
        $company = Company::factory()->create();
        $this->level($company, TeamVisibilityLevel::FullFile);

        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $sale = $this->closedSale($company, $child, 890000, 26700);

        $body = $this->actingAs($leader)
            ->getJson("/api/v1/me/team/{$child->id}/clients")
            ->assertOk()
            ->json();

        $client = $body['data'][0];
        $this->assertSame(TeamVisibilityLevel::FullFile->value, $body['meta']['visibility_level']);
        $this->assertArrayHasKey('phone', $client);
        $this->assertArrayHasKey('health_notes', $client);
        $this->assertArrayHasKey('referrals', $client);
        $this->assertSame('แพ้ยาเพนิซิลลิน', $client['health_notes']);

        // ADR-024 §8 — exactly one row, correct actor and subject.
        $rows = AuditLog::where('action', 'team_client_file.view')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($leader->id, $rows[0]->actor_user_id);
        $this->assertSame($child->id, (int) $rows[0]->auditable_id);
        $this->assertSame(User::class, $rows[0]->auditable_type);
        $this->assertSame($company->id, $rows[0]->company_id);
        $this->assertContains($sale['client']->id, $rows[0]->new_values['client_ids']);
        $this->assertNotNull($rows[0]->created_at);
    }

    /**
     * TASK-111 (D2) — the full_file key set, asserted EXACTLY.
     *
     * `full_file` means "the file as the subordinate sees it", but one field
     * is deliberately narrower even there: the decrypted Thai national ID.
     * ClientResource::viewerMaySeeFullNationalId() (TASK-049) gates it to
     * Super Admin / the client's own Company Admin / the referring agent, and
     * a team leader is none of those — so the leader gets the MASK and the
     * `national_id` key is filtered out of the JSON entirely (the gate emits
     * a MissingValue, which JsonResource::filter() removes).
     *
     * Nothing tested that before, so widening TASK-049's gate later would have
     * leaked a decrypted national ID onto the team screen silently. This test
     * is the tripwire: change that gate and this fails.
     */
    public function test_full_file_exposes_the_file_but_never_the_decrypted_national_id(): void
    {
        $company = Company::factory()->create();
        $this->level($company, TeamVisibilityLevel::FullFile);

        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        // closedSale() stores national_id = '1234567890123'.
        $sale = $this->closedSale($company, $child, 890000, 26700);

        $response = $this->actingAs($leader)
            ->getJson("/api/v1/me/team/{$child->id}/clients")
            ->assertOk();

        $client = $response->json('data.0');

        $keys = array_keys($client);
        sort($keys);
        $this->assertSame([
            'address',
            'client_category_id',
            'client_category_name',
            'company_id',
            'consent_given_at',
            'created_at',
            'date_of_birth',
            'email',
            'health_notes',
            'id',
            'lead_source',
            'name',
            // Present: the MASK is what a leader is entitled to.
            'national_id_masked',
            'occupation',
            'phone',
            'province',
            'referrals',
            'referring_agent_id',
            'status',
            // NOTE: 'national_id' is deliberately NOT in this list. If this
            // assertion starts failing on that key, the TASK-049 gate has been
            // widened and a decrypted national ID is now reaching a leader.
        ], $keys);

        // Belt and braces, and robust to the key being present-but-null if
        // Laravel's MissingValue filtering ever changes shape.
        $this->assertArrayNotHasKey('national_id', $client);
        $this->assertNull($client['national_id'] ?? null);

        // The masked form IS present and IS non-null — proving the assertion
        // above is about the decrypted value, not about the field simply
        // being empty in this fixture.
        $this->assertNotNull($client['national_id_masked']);
        $this->assertSame('*********0123', $client['national_id_masked']);

        // ...and the raw value appears NOWHERE in the response, not in the
        // masked field, not nested in a referral, not anywhere.
        $this->assertStringNotContainsString('1234567890123', $response->getContent());

        // The rest of the file really is disclosed at this level (the point of
        // full_file) — otherwise the assertions above would pass vacuously.
        $this->assertSame($sale['client']->id, $client['id']);
        $this->assertSame('0812345678', $client['phone']);
        $this->assertSame('แพ้ยาเพนิซิลลิน', $client['health_notes']);
    }

    /**
     * TASK-111 (D3) — full_file must not name agents outside the caller's
     * subtree.
     *
     * clientsFor() eager-loads EVERY referral on the client (the client's own
     * deal history is legitimate context), and ReferralResource carries
     * agent.id / agent.name. So before this fix, any client SHARED with an
     * agent from another branch disclosed that agent's identity — walking
     * straight around the 404 that ADR-024 §3 returns when a leader asks
     * about a sibling leader's node.
     */
    public function test_full_file_hides_agent_identities_from_outside_the_subtree(): void
    {
        $company = Company::factory()->create();
        $this->level($company, TeamVisibilityLevel::FullFile);
        // TASK-174 — this case asserts that a co_agent OUTSIDE the subtree is
        // narrowed to null, which presupposes the co-agent split is switched
        // ON for this company (it ships off, D2). With it off the key is
        // absent from ReferralResource altogether and there is nothing to
        // narrow — covered separately in CommissionSplitSettingTest.
        CommissionSplitSetting::create(['company_id' => $company->id, 'is_enabled' => true]);

        $leader = User::factory()->agent()->create([
            'company_id' => $company->id, 'first_name' => 'Mine', 'last_name' => 'Leader',
        ]);
        $child = User::factory()->agent()->create([
            'company_id' => $company->id, 'manager_id' => $leader->id,
            'first_name' => 'Mine', 'last_name' => 'Subordinate',
        ]);

        // A completely separate branch of the same company — the caller gets
        // a 404 if they ask about this node directly, so its members' names
        // must not arrive by another route either.
        $siblingLeader = User::factory()->agent()->create(['company_id' => $company->id]);
        $outsider = User::factory()->agent()->create([
            'company_id' => $company->id, 'manager_id' => $siblingLeader->id,
            'first_name' => 'Sibling', 'last_name' => 'Outsider',
        ]);

        // ONE client, worked by three people.
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'referring_agent_id' => $child->id,
        ]);

        $mine = Referral::factory()->create([
            'company_id' => $company->id, 'client_id' => $client->id,
            'agent_id' => $child->id,
            // TASK-026 split — a co-agent is an agent identity too, and this
            // one belongs to the other branch.
            'co_agent_id' => $outsider->id,
            'split_percentage' => 50,
            'current_stage' => PipelineStage::CompletePayment,
        ]);
        $theirs = Referral::factory()->create([
            'company_id' => $company->id, 'client_id' => $client->id,
            'agent_id' => $outsider->id,
            'current_stage' => PipelineStage::CompleteRegistered,
        ]);
        $mineAsLeader = Referral::factory()->create([
            'company_id' => $company->id, 'client_id' => $client->id,
            'agent_id' => $leader->id,
            'current_stage' => PipelineStage::WaitingAppointment,
        ]);

        $response = $this->actingAs($leader)
            ->getJson("/api/v1/me/team/{$child->id}/clients")
            ->assertOk();

        $referrals = collect($response->json('data.0.referrals'))->keyBy('id');

        // Every row is KEPT — dropping one would misstate how many deals
        // exist on this client, which is legitimate context for the leader.
        $this->assertCount(3, $referrals);

        // In my subtree → named.
        $this->assertSame($child->id, $referrals[$mine->id]['agent']['id']);
        $this->assertSame($child->name, $referrals[$mine->id]['agent']['name']);

        // Me → named (a leader seeing their own name on their own screen).
        $this->assertSame($leader->id, $referrals[$mineAsLeader->id]['agent']['id']);

        // Outside my subtree → identity replaced with null. Deliberately null
        // rather than a placeholder string: the wording is ag-ui's call, not
        // the API's.
        $this->assertNull($referrals[$theirs->id]['agent']);
        $this->assertNull($referrals[$mine->id]['co_agent']);

        // The strongest form of the assertion: the outsider's name is not in
        // the response body at all.
        $this->assertStringNotContainsString('Sibling Outsider', $response->getContent());
        $this->assertStringNotContainsString('Outsider', $response->getContent());
    }

    public function test_an_empty_full_file_list_writes_no_audit_row(): void
    {
        $company = Company::factory()->create();
        $this->level($company, TeamVisibilityLevel::FullFile);

        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $body = $this->actingAs($leader)
            ->getJson("/api/v1/me/team/{$child->id}/clients")
            ->assertOk()
            ->json();

        $this->assertSame([], $body['data']);
        // Nothing was disclosed, so nothing is logged.
        $this->assertSame(0, AuditLog::where('action', 'team_client_file.view')->count());
    }

    // "Changing the company's level changes the payload without any deploy."
    public function test_changing_the_company_level_changes_the_payload(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $this->closedSale($company, $child, 890000, 26700);

        $this->level($company, TeamVisibilityLevel::CountsOnly);
        $this->actingAs($leader)->getJson("/api/v1/me/team/{$child->id}/clients")->assertForbidden();

        $this->level($company, TeamVisibilityLevel::Names);
        $named = $this->actingAs($leader)->getJson("/api/v1/me/team/{$child->id}/clients")->assertOk()->json();
        $this->assertArrayNotHasKey('phone', $named['data'][0]);

        $this->level($company, TeamVisibilityLevel::FullFile);
        $full = $this->actingAs($leader)->getJson("/api/v1/me/team/{$child->id}/clients")->assertOk()->json();
        $this->assertArrayHasKey('phone', $full['data'][0]);

        // The overview echoes the current level too, so the UI never has to
        // guess which shape it is about to receive.
        $overview = $this->actingAs($leader)->getJson('/api/v1/me/team')->assertOk()->json('data');
        $this->assertSame(TeamVisibilityLevel::FullFile->value, $overview['visibility_level']);
    }

    // BR-6 — company B's configured level must never widen company A.
    public function test_another_companys_level_does_not_widen_mine(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->level($companyB, TeamVisibilityLevel::FullFile);

        $leader = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $child = User::factory()->agent()->create(['company_id' => $companyA->id, 'manager_id' => $leader->id]);

        $this->actingAs($leader)
            ->getJson("/api/v1/me/team/{$child->id}/clients")
            ->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Money (BR-3 / BR-4)
    // ---------------------------------------------------------------

    /**
     * TASK-179 (D1) — renamed from ..._read_from_the_ledger_...: only TWO of
     * these three figures come from the ledger now. `sales_satang` is the
     * customer's paid ORDER; `commission_satang` and `my_override_satang`
     * are what the company disbursed. All three stay integer satang (BR-3).
     */
    public function test_money_is_read_as_integer_satang_from_its_own_source(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $sale = $this->closedSale($company, $child, 890000, 26700);

        // The leader's OWN override row, earned from the child's referral.
        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
            'referral_id' => $sale['referral']->id,
            'override_source_agent_id' => $child->id,
            'earned_via' => CommissionEarnedVia::Override,
            'payment_status' => PaymentStatus::Paid,
            'amount_satang' => 8900,
            'sale_price_satang_at_time' => null,
            'paid_at' => now(),
        ]);

        $data = $this->actingAs($leader)->getJson('/api/v1/me/team')->assertOk()->json('data');
        $node = $data['nodes'][0];

        $this->assertSame(890000, $node['sales_satang']);
        $this->assertSame(26700, $node['commission_satang']);
        $this->assertSame(8900, $node['my_override_satang']);

        foreach (['sales_satang', 'commission_satang', 'my_override_satang'] as $key) {
            $this->assertIsInt($node[$key]);
            $this->assertIsInt($data['totals'][$key]);
        }
    }

    // my_override_satang is the CALLER's own earnings from that subordinate
    // — not the subordinate's, and not another upline's.
    public function test_my_override_satang_excludes_another_uplines_override(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $otherUpline = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $sale = $this->closedSale($company, $child, 890000, 26700);

        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $otherUpline->id,
            'referral_id' => $sale['referral']->id,
            'override_source_agent_id' => $child->id,
            'earned_via' => CommissionEarnedVia::Override,
            'payment_status' => PaymentStatus::Paid,
            'amount_satang' => 5000,
            'sale_price_satang_at_time' => null,
            'paid_at' => now(),
        ]);

        $data = $this->actingAs($leader)->getJson('/api/v1/me/team')->assertOk()->json('data');

        $this->assertSame(0, $data['nodes'][0]['my_override_satang']);
        $this->assertSame(0, $data['totals']['my_override_satang']);
    }

    /**
     * TASK-111 (D4) — PENDING ledger rows must not be counted as earnings.
     *
     * Every other money fixture in this file is PaymentStatus::Paid, so the
     * paid-only filters in AgentSalesAggregateService::forAgents() and
     * TeamMonitorService::overrideSatangBySourceAgent() could have been
     * deleted outright without a single test failing. This case exercises all
     * three satang figures with one Paid and one Pending row each.
     *
     * TASK-179 (D1): `sales_satang` is deliberately NOT one of the figures
     * this pending/paid split governs any more — it is the customer's paid
     * ORDER, and the second referral below has none, which is why it still
     * contributes nothing. The commission figures are the ones the ledger's
     * payment_status legitimately gates.
     */
    public function test_pending_ledger_rows_are_excluded_from_every_satang_figure(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        // 1. The subordinate's PAID sale.
        $paidSale = $this->closedSale($company, $child, 890000, 26700);

        // 2. The subordinate's second sale — closed in the pipeline, but the
        //    commission has NOT been paid out yet.
        $pendingClient = Client::factory()->create([
            'company_id' => $company->id,
            'referring_agent_id' => $child->id,
        ]);
        $pendingReferral = Referral::factory()->create([
            'company_id' => $company->id,
            'client_id' => $pendingClient->id,
            'agent_id' => $child->id,
            'current_stage' => PipelineStage::CompletePayment,
        ]);
        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $child->id,
            'referral_id' => $pendingReferral->id,
            'earned_via' => CommissionEarnedVia::Direct,
            'payment_status' => PaymentStatus::Pending,
            'amount_satang' => 99999,
            'sale_price_satang_at_time' => 777777,
            'paid_at' => null,
        ]);

        // 3. The leader's own override off the PAID sale...
        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
            'referral_id' => $paidSale['referral']->id,
            'override_source_agent_id' => $child->id,
            'earned_via' => CommissionEarnedVia::Override,
            'payment_status' => PaymentStatus::Paid,
            'amount_satang' => 8900,
            'sale_price_satang_at_time' => null,
            'paid_at' => now(),
        ]);

        // 4. ...and the leader's own UNPAID override off the pending sale.
        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
            'referral_id' => $pendingReferral->id,
            'override_source_agent_id' => $child->id,
            'earned_via' => CommissionEarnedVia::Override,
            'payment_status' => PaymentStatus::Pending,
            'amount_satang' => 5555,
            'sale_price_satang_at_time' => null,
            'paid_at' => null,
        ]);

        $data = $this->actingAs($leader)->getJson('/api/v1/me/team')->assertOk()->json('data');
        $node = $data['nodes'][0];

        // Only the paid figures — the pending amounts are excluded from all
        // three, on the node and in the subtree rollup alike.
        $this->assertSame(890000, $node['sales_satang']);
        $this->assertSame(26700, $node['commission_satang']);
        $this->assertSame(8900, $node['my_override_satang']);
        $this->assertSame(890000, $data['totals']['sales_satang']);
        $this->assertSame(26700, $data['totals']['commission_satang']);
        $this->assertSame(8900, $data['totals']['my_override_satang']);

        // ...while the DEAL and CLIENT counts are NOT payment-filtered: they
        // come from referrals, not the ledger, so both sales still count.
        // Asserted so a future "just filter everything by paid" change is
        // caught rather than absorbed.
        $this->assertSame(2, $node['total_deals']);
        $this->assertSame(2, $node['closed_deals']);
        $this->assertSame(2, $node['client_count']);
    }

    public function test_no_float_appears_anywhere_in_the_team_payload(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $this->closedSale($company, $child, 890000, 26700);

        $data = $this->actingAs($leader)->getJson('/api/v1/me/team')->assertOk()->json('data');

        $this->assertNoFloats($data, 'data');
    }

    /**
     * BR-3 — float/double are forbidden for money. The team payload carries
     * no percentage either (unlike the admin cockpit's `conversion`), so a
     * float anywhere in it is a bug by definition.
     *
     * @param  mixed  $value
     */
    private function assertNoFloats($value, string $path): void
    {
        if (is_float($value)) {
            $this->fail("Float found in the payload at {$path}");
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->assertNoFloats($child, $path.'.'.$key);
            }
        }
    }

    // ---------------------------------------------------------------
    // Read-only by construction (ADR-024 §7)
    // ---------------------------------------------------------------

    public function test_no_write_verb_is_routable_under_me_team(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            $method = $verb.'Json';

            $this->actingAs($leader)->{$method}('/api/v1/me/team')
                ->assertStatus(405);

            $this->actingAs($leader)->{$method}("/api/v1/me/team/{$child->id}/clients")
                ->assertStatus(405);
        }
    }

    /**
     * TASK-111 (D8) / ADR-024 §7 — a leader must never be able to settle
     * their team's payout.
     *
     * The ADR states this explicitly ("CommissionLedgerPolicy::markPaid stays
     * Company/Super Admin only"), and CommissionLedgerPolicy already enforces
     * it, but nothing exercised the combination this feature actually
     * introduced: an agent who now HAS a downline, pointing the one mutable
     * commission endpoint at a subordinate's row. This is a regression lock on
     * that Policy from the team-monitor side — the Policy itself is untouched.
     */
    public function test_a_leader_cannot_mark_a_subordinates_commission_as_paid(): void
    {
        $company = Company::factory()->create();
        $this->level($company, TeamVisibilityLevel::FullFile);

        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $client = Client::factory()->create([
            'company_id' => $company->id,
            'referring_agent_id' => $child->id,
        ]);
        $referral = Referral::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $child->id,
            'current_stage' => PipelineStage::CompletePayment,
        ]);

        // The SUBORDINATE's unpaid commission.
        $theirs = CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $child->id,
            'referral_id' => $referral->id,
            'earned_via' => CommissionEarnedVia::Direct,
            'payment_status' => PaymentStatus::Pending,
            'amount_satang' => 26700,
            'paid_at' => null,
        ]);

        // The LEADER's own unpaid override off the same referral.
        $mine = CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $leader->id,
            'referral_id' => $referral->id,
            'override_source_agent_id' => $child->id,
            'earned_via' => CommissionEarnedVia::Override,
            'payment_status' => PaymentStatus::Pending,
            'amount_satang' => 8900,
            'sale_price_satang_at_time' => null,
            'paid_at' => null,
        ]);

        $this->actingAs($leader)
            ->postJson("/api/v1/commission-ledger/{$theirs->id}/mark-paid")
            ->assertForbidden();

        // Not on their own row either — self-dealing is the reason the Policy
        // is Company/Super Admin only in the first place.
        $this->actingAs($leader)
            ->postJson("/api/v1/commission-ledger/{$mine->id}/mark-paid")
            ->assertForbidden();

        // BR-4 — and the immutable ledger really is unchanged.
        foreach ([$theirs, $mine] as $entry) {
            $this->assertDatabaseHas('commission_ledger', [
                'id' => $entry->id,
                'payment_status' => PaymentStatus::Pending->value,
                'paid_at' => null,
            ]);
        }
    }

    // ---------------------------------------------------------------
    // /me/home extension (ADR-024 §9)
    // ---------------------------------------------------------------

    public function test_me_home_exposes_direct_reports_count(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $childOne = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        // A grandchild must NOT inflate the DIRECT count.
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $childOne->id]);

        $data = $this->actingAs($leader)->getJson('/api/v1/me/home')->assertOk()->json('data');
        $this->assertSame(2, $data['direct_reports_count']);

        $lonerData = $this->actingAs($childOne)->getJson('/api/v1/me/home')->assertOk()->json('data');
        $this->assertSame(1, $lonerData['direct_reports_count']);

        $noTeam = User::factory()->agent()->create(['company_id' => $company->id]);
        $noTeamData = $this->actingAs($noTeam)->getJson('/api/v1/me/home')->assertOk()->json('data');
        $this->assertSame(0, $noTeamData['direct_reports_count']);
    }

    public function test_the_team_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/me/team')->assertUnauthorized();
        $this->getJson('/api/v1/me/team/1/clients')->assertUnauthorized();
    }
}
