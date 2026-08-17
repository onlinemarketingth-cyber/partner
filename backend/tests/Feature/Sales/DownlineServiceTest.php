<?php

namespace Tests\Feature\Sales;

use App\Enums\TeamVisibilityLevel;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\TeamVisibilitySetting;
use App\Models\User;
use App\Services\Sales\DownlineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// TASK-106 / ADR-024 §4-§5 — DownlineService is the authorisation primitive
// behind every /me/team endpoint (TASK-107), so it is tested directly rather
// than only through an HTTP surface that does not exist yet.
//
// Covers the TASK-106 acceptance criteria:
//   - depth > 1                    → test_subtree_walks_more_than_one_level
//   - MAX_DEPTH stop               → test_subtree_stops_at_max_depth
//   - injected cycle terminates    → test_subtree_terminates_on_a_manual_cycle
//                                    test_subtree_terminates_on_a_self_referencing_manager
//   - MAX_NODES cap                → test_subtree_stops_at_max_nodes
//   - tenant isolation (BR-6)      → test_subtree_never_crosses_a_tenant_boundary
//                                    test_is_in_subtree_rejects_a_cross_tenant_candidate
//   - missing settings row         → test_level_falls_back_to_counts_only_when_no_row_exists
//   - disabled settings row        → test_level_falls_back_to_counts_only_when_disabled
//
// TASK-111 additions:
//   - D1 the master switch is its own question, not a level
//                                  → test_is_enabled_is_false_when_the_master_switch_is_off
//                                    test_is_enabled_is_true_for_an_unconfigured_company
//                                    test_is_enabled_is_false_for_a_user_without_a_company
//   - D5 unrecognised stored level → test_an_unrecognised_stored_level_degrades_to_counts_only
class DownlineServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DownlineService
    {
        return app(DownlineService::class);
    }

    public function test_direct_reports_returns_only_the_immediate_level(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $grandchild = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $child->id]);

        $reports = $this->service()->directReports($leader);

        $this->assertEquals([$child->id], $reports->pluck('id')->all());
        $this->assertNotContains($grandchild->id, $reports->pluck('id')->all());
    }

    public function test_an_agent_with_no_reports_gets_an_empty_downline(): void
    {
        $company = Company::factory()->create();
        $loner = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->assertTrue($this->service()->directReports($loner)->isEmpty());
        $this->assertTrue($this->service()->subtreeIds($loner)->isEmpty());
    }

    public function test_subtree_walks_more_than_one_level(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $grandchild = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $child->id]);
        $greatGrandchild = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $grandchild->id]);

        $ids = $this->service()->subtreeIds($leader);

        $this->assertEqualsCanonicalizing(
            [$child->id, $grandchild->id, $greatGrandchild->id],
            $ids->all(),
        );
        // The leader is strictly excluded — see isInSubtree()'s docblock.
        $this->assertNotContains($leader->id, $ids->all());
    }

    // ADR-024 §4 — a manual cycle inserted directly in the DB must terminate
    // the walk, not hang the request. UserService::assertValidManager()
    // refuses to create one on the write path, so this is deliberately
    // written straight to the DB to simulate bad/restored data.
    public function test_subtree_terminates_on_a_manual_cycle(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $grandchild = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $child->id]);

        // Close the loop: the grandchild now "manages" the leader.
        DB::table('users')->where('id', $leader->id)->update(['manager_id' => $grandchild->id]);

        $ids = $this->service()->subtreeIds($leader->fresh());

        $this->assertEqualsCanonicalizing([$child->id, $grandchild->id], $ids->all());
    }

    public function test_subtree_terminates_on_a_self_referencing_manager(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        // A row that is its own manager: without the visited set this level
        // would keep re-yielding itself forever.
        DB::table('users')->where('id', $child->id)->update(['manager_id' => $child->id]);

        $ids = $this->service()->subtreeIds($leader);

        $this->assertSame([], $ids->all());

        // ...and the same user IS reachable when the leader really is above
        // them, proving the guard drops the repeat visit, not the node.
        DB::table('users')->where('id', $child->id)->update(['manager_id' => $leader->id]);
        $this->assertSame([$child->id], $this->service()->subtreeIds($leader->fresh())->all());
    }

    public function test_subtree_stops_at_max_depth(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);

        // A single chain deeper than the circuit breaker allows.
        $parent = $leader;
        for ($i = 0; $i < DownlineService::MAX_DEPTH + 5; $i++) {
            $parent = User::factory()->agent()->create([
                'company_id' => $company->id,
                'manager_id' => $parent->id,
            ]);
        }

        $this->assertCount(DownlineService::MAX_DEPTH, $this->service()->subtreeIds($leader));
    }

    public function test_subtree_stops_at_max_nodes(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);

        // Bulk-inserted rather than factory-created: this test only needs
        // valid rows carrying manager_id/company_id, and 2100 individual
        // model saves would dominate the suite's runtime.
        $rows = [];
        for ($i = 0; $i < DownlineService::MAX_NODES + 100; $i++) {
            $rows[] = [
                'name' => "Bulk Agent {$i}",
                'email' => "bulk-downline-{$i}@example.test",
                'password' => 'not-a-real-hash',
                'company_id' => $company->id,
                'role' => UserRole::Agent->value,
                'manager_id' => $leader->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        // Chunked to stay well under SQLite's bound-parameter limit.
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        $this->assertCount(DownlineService::MAX_NODES, $this->service()->subtreeIds($leader));
    }

    // BR-6 / CLAUDE.md §5 — the headline acceptance criterion: a leader in
    // company A must never receive an id belonging to company B, even if a
    // manager_id row somehow crosses tenants.
    public function test_subtree_never_crosses_a_tenant_boundary(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $leader = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $ownChild = User::factory()->agent()->create(['company_id' => $companyA->id, 'manager_id' => $leader->id]);

        // An illegal cross-tenant edge (UserService::assertValidManager()
        // rejects this on write; the factory bypasses that Service on purpose).
        $foreignChild = User::factory()->agent()->create(['company_id' => $companyB->id, 'manager_id' => $leader->id]);
        // ...and a descendant hanging off the foreign node, to prove the walk
        // does not resume inside company B one level down either.
        $foreignGrandchild = User::factory()->agent()->create(['company_id' => $companyB->id, 'manager_id' => $foreignChild->id]);

        $ids = $this->service()->subtreeIds($leader)->all();

        $this->assertSame([$ownChild->id], $ids);
        $this->assertNotContains($foreignChild->id, $ids);
        $this->assertNotContains($foreignGrandchild->id, $ids);
        $this->assertEquals([$ownChild->id], $this->service()->directReports($leader)->pluck('id')->all());
    }

    // A Super Admin has no company_id; without the explicit guard the
    // company filter would degrade to `company_id IS NULL` and match every
    // other Super Admin on the platform.
    public function test_a_user_without_a_company_has_no_downline(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->create(['manager_id' => $superAdmin->id]);

        $this->assertTrue($this->service()->directReports($superAdmin)->isEmpty());
        $this->assertTrue($this->service()->subtreeIds($superAdmin)->isEmpty());
        $this->assertSame(TeamVisibilityLevel::CountsOnly, $this->service()->resolveLevel($superAdmin));
    }

    public function test_is_in_subtree_accepts_a_descendant_at_any_depth(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $grandchild = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $child->id]);

        $this->assertTrue($this->service()->isInSubtree($leader, $child->id));
        $this->assertTrue($this->service()->isInSubtree($leader, $grandchild->id));
    }

    // TASK-110 case 2 (IDOR — upward): a subordinate must not be able to
    // point the team endpoints at their own manager.
    public function test_is_in_subtree_rejects_an_upward_or_self_candidate(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $this->assertFalse($this->service()->isInSubtree($child, $leader->id));
        $this->assertFalse($this->service()->isInSubtree($leader, $leader->id));
    }

    // TASK-110 case 1 (IDOR — sibling): two leaders in the SAME company must
    // not see into each other's trees.
    public function test_is_in_subtree_rejects_a_sibling_leaders_node(): void
    {
        $company = Company::factory()->create();
        $leaderOne = User::factory()->agent()->create(['company_id' => $company->id]);
        $leaderTwo = User::factory()->agent()->create(['company_id' => $company->id]);
        $twosChild = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leaderTwo->id]);

        $this->assertFalse($this->service()->isInSubtree($leaderOne, $twosChild->id));
    }

    public function test_is_in_subtree_rejects_a_cross_tenant_candidate(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $leader = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $foreign = User::factory()->agent()->create(['company_id' => $companyB->id, 'manager_id' => $leader->id]);

        $this->assertFalse($this->service()->isInSubtree($leader, $foreign->id));
    }

    // ADR-024 §5 — fail closed: never configured means counts_only.
    public function test_level_falls_back_to_counts_only_when_no_row_exists(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->assertDatabaseMissing('team_visibility_settings', ['company_id' => $company->id]);
        $this->assertSame(TeamVisibilityLevel::CountsOnly, $this->service()->resolveLevel($leader));
    }

    // ADR-024 §5 — the master switch being off must behave EXACTLY like a
    // missing row, even though a wider level is stored on the row.
    public function test_level_falls_back_to_counts_only_when_disabled(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);

        TeamVisibilitySetting::create([
            'company_id' => $company->id,
            'client_visibility_level' => TeamVisibilityLevel::FullFile->value,
            'is_enabled' => false,
        ]);

        $this->assertSame(TeamVisibilityLevel::CountsOnly, $this->service()->resolveLevel($leader));
    }

    // TASK-111 (D5) — the `tryFrom(...) ?? default()` arm in resolveLevel() is
    // documented as the guard against "a hand-edited row, a half-rolled-back
    // migration". Nothing tested it, and it was in fact UNREACHABLE: Eloquent's
    // enum cast uses BackedEnum::from(), which throws a ValueError before
    // resolveLevel() ever sees the string, so one bad row 500'd the team screen
    // for that tenant instead of failing closed. TeamVisibilitySettingService
    // now reads the raw attribute; this test locks that in.
    public function test_an_unrecognised_stored_level_degrades_to_counts_only(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);

        TeamVisibilitySetting::create([
            'company_id' => $company->id,
            'client_visibility_level' => TeamVisibilityLevel::FullFile->value,
            'is_enabled' => true,
        ]);

        // Written straight to the DB, bypassing the model's enum cast — the
        // only way to reproduce the bad-data case the fallback exists for.
        DB::table('team_visibility_settings')
            ->where('company_id', $company->id)
            ->update(['client_visibility_level' => 'everything_please']);

        $this->assertSame(TeamVisibilityLevel::CountsOnly, $this->service()->resolveLevel($leader));

        // ...and the FEATURE is still on. A garbage level must narrow what a
        // leader sees, not silently switch the whole screen off — those are
        // two different questions (TASK-111 D1) and a corrupt level column
        // must not be able to answer the other one.
        $this->assertTrue($this->service()->isEnabled($leader));
    }

    // ---------------------------------------------------------------
    // TASK-111 (D1) — isEnabled() is a SEPARATE question from resolveLevel()
    // ---------------------------------------------------------------

    public function test_is_enabled_is_false_when_the_master_switch_is_off(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);

        TeamVisibilitySetting::create([
            'company_id' => $company->id,
            // Widest level stored — the switch must win regardless.
            'client_visibility_level' => TeamVisibilityLevel::FullFile->value,
            'is_enabled' => false,
        ]);

        $this->assertFalse($this->service()->isEnabled($leader));
    }

    // An unconfigured tenant fails closed on the LEVEL (counts_only) but the
    // feature itself is on — that is the shipped default and the reason the
    // two questions cannot share one answer.
    public function test_is_enabled_is_true_for_an_unconfigured_company(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->assertDatabaseMissing('team_visibility_settings', ['company_id' => $company->id]);
        $this->assertTrue($this->service()->isEnabled($leader));
        $this->assertSame(TeamVisibilityLevel::CountsOnly, $this->service()->resolveLevel($leader));
    }

    public function test_is_enabled_is_false_for_a_user_without_a_company(): void
    {
        // A Super Admin: no company, no downline, and no team screen
        // (ADR-024 §1). Fail closed rather than inherit the unconfigured
        // tenant's "on" default via forCompany(null).
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertFalse($this->service()->isEnabled($superAdmin));
    }

    public function test_level_reflects_the_configured_value_when_enabled(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);

        $setting = TeamVisibilitySetting::create([
            'company_id' => $company->id,
            'client_visibility_level' => TeamVisibilityLevel::Names->value,
            'is_enabled' => true,
        ]);

        $this->assertSame(TeamVisibilityLevel::Names, $this->service()->resolveLevel($leader));

        $setting->update(['client_visibility_level' => TeamVisibilityLevel::FullFile->value]);

        $this->assertSame(TeamVisibilityLevel::FullFile, $this->service()->resolveLevel($leader->fresh()));
    }

    // BR-6 — company B's configured level must never leak into company A's
    // resolution.
    public function test_level_is_resolved_per_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $leaderA = User::factory()->agent()->create(['company_id' => $companyA->id]);

        TeamVisibilitySetting::create([
            'company_id' => $companyB->id,
            'client_visibility_level' => TeamVisibilityLevel::FullFile->value,
            'is_enabled' => true,
        ]);

        $this->assertSame(TeamVisibilityLevel::CountsOnly, $this->service()->resolveLevel($leaderA));
    }

    // Soft-deleted (deactivated, UserService::deactivate()) agents must drop
    // out of the tree — companyScoped() drops only TenantScope, never the
    // SoftDeletingScope.
    public function test_a_deactivated_agent_leaves_the_downline(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $child->delete();

        $this->assertTrue($this->service()->subtreeIds($leader)->isEmpty());
        $this->assertFalse($this->service()->isInSubtree($leader, $child->id));
    }
}
