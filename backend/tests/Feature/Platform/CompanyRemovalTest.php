<?php

namespace Tests\Feature\Platform;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Badge;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\GamificationRule;
use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Deleting a company, and the บริษัททดสอบ flag that decides how much may go
 * with it (2026-09-26, CompanyRemovalService).
 *
 * The owner's two sentences, as tests:
 *   · a TEST company is deleted whole — users, commission, everything;
 *   · a REAL company is deleted only while it holds no agent, customer, deal,
 *     order, commission or withdrawal.
 *
 * The fixture for the first is not hand-rolled. `uat:seed-commission-plans`
 * drives real sales through the real services — referrals, orders, slips,
 * confirmations, commission rows, ranks, placements, certifications, payout
 * details — so "everything" here means everything those services write, not
 * everything this test remembered to create.
 */
class CompanyRemovalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->superAdmin()->create();
    }

    /** Two populated tenants: the one to delete and the one that must not move. */
    private function twoPopulatedTenants(): array
    {
        $this->artisan('uat:seed-commission-plans', ['--force' => true, '--plan' => ['stairstep_breakaway', 'unilevel']])
            ->assertSuccessful();

        $doomed = Company::withoutGlobalScopes()->where('slug', 'uat-plan-stairstep-breakaway')->firstOrFail();
        $bystander = Company::withoutGlobalScopes()->where('slug', 'uat-plan-unilevel')->firstOrFail();

        return [$doomed, $bystander];
    }

    /** @return array<string, int> table => rows carrying this company_id */
    private function footprint(int $companyId): array
    {
        $rows = [];
        foreach (Schema::getTables() as $t) {
            $table = (string) ($t['name'] ?? '');
            if ($table === '' || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            $count = DB::table($table)->where('company_id', $companyId)->count();
            if ($count > 0) {
                $rows[$table] = $count;
            }
        }

        return $rows;
    }

    /** @return array<string, int> table => rows with a NULL company_id (platform-owned) */
    private function platformRows(): array
    {
        $rows = [];
        foreach (Schema::getTables() as $t) {
            $table = (string) ($t['name'] ?? '');
            if ($table === '' || $table === 'users' || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            $rows[$table] = DB::table($table)->whereNull('company_id')->count();
        }

        return $rows;
    }

    private function purge(Company $company, string $name)
    {
        return $this->actingAs($this->owner)->deleteJson("/api/v1/companies/{$company->id}/purge", ['confirm_name' => $name]);
    }

    /* ── บริษัททดสอบ: deleted whole ──────────────────────────────────── */

    public function test_a_test_company_is_deleted_with_everything_in_it(): void
    {
        [$doomed, $bystander] = $this->twoPopulatedTenants();
        $doomed->forceFill(['is_test' => true])->save();

        /*
         * Company-owned rows in the four tables whose company_id is
         * nullOnDelete. The seeder writes none of them, and those are exactly
         * the rows that would survive a naive delete as PLATFORM-WIDE rows —
         * this company's badge, reward and announcement shown to everyone.
         */
        Badge::factory()->create(['company_id' => $doomed->id]);
        GamificationRule::factory()->create(['company_id' => $doomed->id]);
        DB::table('reward_items')->insert(['company_id' => $doomed->id, 'name' => 'UAT reward', 'cost_points' => 10, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('announcements')->insert(['company_id' => $doomed->id, 'title' => 'UAT', 'content' => 'UAT', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        /*
         * And rows that hold a RESTRICT key onto another company table: a
         * badge someone earned, a reward someone redeemed, a banner and a
         * lesson module pointing at a product. None is written by the seeder,
         * and each one refuses to let its parent go first — the delete has
         * to find an order that works, not rely on the one tables happen to
         * be listed in.
         */
        $agentId = DB::table('users')->where('company_id', $doomed->id)->where('role', UserRole::Agent->value)->value('id');
        $productId = DB::table('products')->where('company_id', $doomed->id)->value('id');
        $badgeId = DB::table('badges')->where('company_id', $doomed->id)->value('id');
        $rewardId = DB::table('reward_items')->where('company_id', $doomed->id)->value('id');
        DB::table('user_badges')->insert(['company_id' => $doomed->id, 'user_id' => $agentId, 'badge_id' => $badgeId, 'earned_at' => now()]);
        DB::table('reward_redemptions')->insert(['company_id' => $doomed->id, 'user_id' => $agentId, 'reward_item_id' => $rewardId, 'points_spent' => 10, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('storefront_banners')->insert(['company_id' => $doomed->id, 'product_id' => $productId, 'image_path' => 'uat/banner.png', 'created_at' => now(), 'updated_at' => now()]);
        Module::factory()->create([
            'company_id' => $doomed->id,
            'cert_tier_id' => CertTier::factory()->create()->id,
            'product_id' => $productId,
        ]);

        $userIds = DB::table('users')->where('company_id', $doomed->id)->pluck('id');
        $this->assertGreaterThan(0, $userIds->count());
        $this->assertGreaterThan(0, DB::table('commission_ledger')->where('company_id', $doomed->id)->count());
        $bystanderBefore = $this->footprint($bystander->id);
        $platformBefore = $this->platformRows();

        $this->getJson("/api/v1/companies/{$doomed->id}/removal")->assertUnauthorized();
        $this->actingAs($this->owner)->getJson("/api/v1/companies/{$doomed->id}/removal")
            ->assertOk()
            ->assertJsonPath('data.mode', 'wipe');

        $this->purge($doomed, $doomed->name)->assertNoContent();

        $this->assertNull(Company::withoutGlobalScopes()->withTrashed()->find($doomed->id));
        $this->assertSame(0, DB::table('users')->whereIn('id', $userIds)->count(), 'its users go with it');
        $this->assertSame([], $this->footprint($doomed->id), 'no table still holds a row for it');

        // BR-6 — the tenant next door did not lose a single row.
        $this->assertSame($bystanderBefore, $this->footprint($bystander->id));

        /*
         * Nothing of it survives as a PLATFORM row. Several tables hang off
         * company_id with nullOnDelete, because a null company_id there means
         * "shared by every company" — products, brands, categories, pipeline
         * templates, theme presets, badges, rewards. Deleting the company
         * first would turn its products into everybody's products. audit_logs
         * is the one table allowed to gain null rows: the trail is kept.
         */
        $platformAfter = $this->platformRows();
        unset($platformBefore['audit_logs'], $platformAfter['audit_logs']);
        $this->assertSame($platformBefore, $platformAfter, 'the deleted company leaked rows into the shared catalogue');

        // §6 — deleting money is logged, and the log outlives the company.
        $entry = AuditLog::where('action', 'company.deleted')->where('auditable_id', $doomed->id)->firstOrFail();
        $this->assertNull($entry->company_id);
        $this->assertSame($this->owner->id, $entry->actor_user_id);
        $this->assertSame('wipe', $entry->old_values['mode']);
    }

    public function test_the_name_must_be_typed_back_exactly(): void
    {
        [$doomed] = $this->twoPopulatedTenants();
        $doomed->forceFill(['is_test' => true])->save();
        $before = $this->footprint($doomed->id);

        $this->purge($doomed, 'UAT')->assertStatus(422)->assertJsonValidationErrors('confirm_name');
        $this->actingAs($this->owner)->deleteJson("/api/v1/companies/{$doomed->id}/purge")->assertStatus(422);

        $this->assertSame($before, $this->footprint($doomed->id), 'nothing was touched');
    }

    /* ── บริษัทจริง: only while empty ──────────────────────────────────── */

    public function test_a_real_company_with_business_data_cannot_be_deleted(): void
    {
        [$real] = $this->twoPopulatedTenants(); // is_test defaults to false
        $before = $this->footprint($real->id);

        $assessment = $this->actingAs($this->owner)->getJson("/api/v1/companies/{$real->id}/removal")
            ->assertOk()->json('data');

        $this->assertSame('blocked', $assessment['mode']);
        $keys = array_column($assessment['blockers'], 'key');
        $this->assertContains('agents', $keys);
        $this->assertContains('commission', $keys);
        $this->assertContains('orders', $keys);

        $this->purge($real, $real->name)->assertStatus(422);

        $this->assertSame($before, $this->footprint($real->id));
    }

    public function test_an_empty_real_company_can_be_deleted_admins_and_settings_included(): void
    {
        // Admins and configuration do not count as "data" — the owner's rule.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($this->owner)->getJson("/api/v1/companies/{$company->id}/removal")
            ->assertOk()
            ->assertJsonPath('data.mode', 'empty')
            ->assertJsonPath('data.blockers', []);

        $this->purge($company, $company->name)->assertNoContent();

        $this->assertNull(Company::withoutGlobalScopes()->withTrashed()->find($company->id));
        $this->assertNull(User::withoutGlobalScopes()->withTrashed()->find($admin->id));
    }

    public function test_one_removed_applicant_is_still_an_applicant(): void
    {
        // A soft-deleted sign-up is data somebody once gave this company.
        $company = Company::factory()->create();
        $applicant = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Agent]);
        $applicant->delete();

        $this->actingAs($this->owner)->getJson("/api/v1/companies/{$company->id}/removal")
            ->assertOk()
            ->assertJsonPath('data.mode', 'blocked')
            ->assertJsonPath('data.blockers.0.key', 'agents');
    }

    /* ── the flag ──────────────────────────────────────────────────────── */

    public function test_the_flag_goes_on_only_for_an_empty_company(): void
    {
        [$real] = $this->twoPopulatedTenants();

        $this->actingAs($this->owner)->putJson("/api/v1/companies/{$real->id}/test-mode", ['is_test' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_test');
        $this->assertFalse((bool) $real->fresh()->is_test, 'a real company cannot be relabelled to be wiped');

        $empty = Company::factory()->create();
        $this->actingAs($this->owner)->putJson("/api/v1/companies/{$empty->id}/test-mode", ['is_test' => true])
            ->assertOk()
            ->assertJsonPath('data.is_test', true);
    }

    public function test_going_live_is_one_way(): void
    {
        $company = Company::factory()->create();
        $company->forceFill(['is_test' => true])->save();

        $this->actingAs($this->owner)->putJson("/api/v1/companies/{$company->id}/test-mode", ['is_test' => false])
            ->assertOk()
            ->assertJsonPath('data.is_test', false);
        $this->assertNotNull($company->fresh()->went_live_at);

        // Still empty — and still refused. Once live, never a test again.
        $this->actingAs($this->owner)->putJson("/api/v1/companies/{$company->id}/test-mode", ['is_test' => true])
            ->assertStatus(422);

        $this->assertTrue(AuditLog::where('action', 'company.went_live')->where('auditable_id', $company->id)->exists());
    }

    public function test_the_flag_cannot_be_set_through_the_general_update(): void
    {
        // The one-way door means nothing if PUT /companies walks round it.
        [$real] = $this->twoPopulatedTenants();

        $this->actingAs($this->owner)->putJson("/api/v1/companies/{$real->id}", ['is_test' => true])->assertOk();

        $this->assertFalse((bool) $real->fresh()->is_test);
    }

    public function test_a_company_can_be_born_a_test_company(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/companies', [
            'name' => 'UAT ใหม่',
            'slug' => 'uat-new',
            'is_test' => true,
        ])->assertCreated()->json('data.id');

        $this->assertTrue((bool) Company::withoutGlobalScopes()->find($id)->is_test);
    }

    /* ── who ───────────────────────────────────────────────────────────── */

    public function test_only_a_super_admin_can_see_flag_or_delete(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->getJson("/api/v1/companies/{$company->id}/removal")->assertForbidden();
        $this->actingAs($admin)->putJson("/api/v1/companies/{$company->id}/test-mode", ['is_test' => true])->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/v1/companies/{$company->id}/purge", ['confirm_name' => $company->name])->assertForbidden();

        $this->assertNotNull(Company::withoutGlobalScopes()->find($company->id));
    }

    /* ── ปิดบริษัท ─────────────────────────────────────────────────────── */

    public function test_closing_and_reopening_are_audited_and_editing_the_name_is_not(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->owner)->putJson("/api/v1/companies/{$company->id}", ['is_active' => false])->assertOk();
        $this->actingAs($this->owner)->putJson("/api/v1/companies/{$company->id}", ['is_active' => true])->assertOk();
        $this->actingAs($this->owner)->putJson("/api/v1/companies/{$company->id}", ['name' => 'ชื่อใหม่'])->assertOk();

        $actions = AuditLog::where('auditable_type', Company::class)->where('auditable_id', $company->id)
            ->orderBy('id')->pluck('action')->all();
        $this->assertSame(['company.closed', 'company.reopened'], $actions);
        $this->assertSame($this->owner->id, AuditLog::where('action', 'company.closed')->value('actor_user_id'));
    }
}
