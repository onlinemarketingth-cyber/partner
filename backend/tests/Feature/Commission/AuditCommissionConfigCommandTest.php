<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionBasis;
use App\Enums\CommissionPlanType;
use App\Models\AuditLog;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The audit command's only promise is that it is safe to run on production
 * before anything has been decided — so the test that matters most is the one
 * proving it wrote nothing.
 *
 * The rest check that each finding actually fires, because a report that
 * silently misses the thing it exists to find is worse than no report: it
 * would be read as "nothing to worry about" and used to justify skipping the
 * guard work entirely.
 */
class AuditCommissionConfigCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Every table the command touches, so "wrote nothing" can be measured. */
    private const WATCHED = ['companies', 'products', 'users', 'commission_ledger', 'audit_logs'];

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $out = [];
        foreach (self::WATCHED as $table) {
            $out[$table] = DB::table($table)->count();
        }

        return $out;
    }

    public function test_it_writes_absolutely_nothing(): void
    {
        $company = Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::Unilevel->value,
            'commission_basis' => CommissionBasis::Price->value,
        ]);
        Product::factory()->for($company)->create();
        User::factory()->agent()->for($company)->create();

        $before = $this->rowCounts();

        $this->artisan('commission:audit-config')->assertExitCode(0);

        $this->assertSame(
            $before,
            $this->rowCounts(),
            'commission:audit-config changed the database — it is documented as read-only and is run on production before any guard exists',
        );
    }

    public function test_it_names_a_product_whose_plan_disagrees_with_its_company(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel->value]);
        Product::factory()->for($company)->create([
            'name' => 'แพ็กเกจที่ตั้งผิด',
            'commission_plan_type' => CommissionPlanType::Binary->value,
        ]);
        Product::factory()->for($company)->create(['commission_plan_type' => null]);

        $this->artisan('commission:audit-config', ['--company' => $company->id])
            ->expectsOutputToContain('แพ็กเกจที่ตั้งผิด → binary')
            ->assertExitCode(0);
    }

    public function test_it_stays_quiet_when_every_product_inherits_the_company_plan(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel->value]);
        Product::factory()->for($company)->create(['commission_plan_type' => null]);

        $this->artisan('commission:audit-config', ['--company' => $company->id])
            ->expectsOutputToContain('สินค้าที่ตั้งแผนสวนบริษัท')
            ->doesntExpectOutputToContain('รายการ')
            ->assertExitCode(0);
    }

    public function test_it_reports_a_basis_change_made_after_money_was_already_paid(): void
    {
        $company = Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::Unilevel->value,
            'commission_basis' => CommissionBasis::PointValue->value,
        ]);
        $agent = User::factory()->agent()->for($company)->create();

        CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'created_at' => now()->subDays(30),
        ]);

        AuditLog::create([
            'company_id' => $company->id,
            'actor_user_id' => $agent->id,
            'action' => 'commission_basis.updated',
            'auditable_type' => Company::class,
            'auditable_id' => $company->id,
            'old_values' => ['commission_basis' => 'price'],
            'new_values' => ['commission_basis' => 'pv'],
        ]);

        $this->artisan('commission:audit-config', ['--company' => $company->id])
            ->expectsOutputToContain('เคยเปลี่ยนแผน/ฐานหลังมียอดขายแล้ว: 1 ครั้ง')
            ->expectsOutputToContain('price → pv')
            ->assertExitCode(0);
    }

    public function test_it_warns_while_the_basis_is_still_changeable_despite_sales(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel->value]);
        $agent = User::factory()->agent()->for($company)->create();
        CommissionLedger::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);

        $this->artisan('commission:audit-config', ['--company' => $company->id])
            ->expectsOutputToContain('มียอดขายแล้ว แต่ระบบยังยอมให้เปลี่ยนแผนและฐานได้อยู่')
            ->assertExitCode(0);
    }

    public function test_it_counts_products_with_no_pv_on_a_pv_company(): void
    {
        $onPv = Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::Unilevel->value,
            'commission_basis' => CommissionBasis::PointValue->value,
        ]);
        Product::factory()->for($onPv)->create(['pv_satang' => null, 'is_active' => true]);

        $this->artisan('commission:audit-config', ['--company' => $onPv->id])
            ->expectsOutputToContain('— คิดจากราคาขายแทน')
            ->assertExitCode(0);
    }

    /**
     * Its own test, not a second half: two artisan() calls in one test share a
     * console output buffer, so a negative assertion on the second run also
     * sees the first run's output and fails for the wrong reason.
     */
    public function test_it_says_nothing_about_pv_on_a_price_basis_company(): void
    {
        $onPrice = Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::Unilevel->value,
            'commission_basis' => CommissionBasis::Price->value,
        ]);
        Product::factory()->for($onPrice)->create(['pv_satang' => null, 'is_active' => true]);

        // A price-basis company never reads pv_satang, so an empty column there
        // is not a gap and must not be reported as one.
        $this->artisan('commission:audit-config', ['--company' => $onPrice->id])
            ->doesntExpectOutputToContain('สินค้าที่ยังไม่ได้ตั้ง PV:')
            ->assertExitCode(0);
    }

    public function test_it_says_a_generation_company_with_no_rank_ladder_pays_nobody(): void
    {
        $company = Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::Generation->value,
        ]);

        $this->artisan('commission:audit-config', ['--company' => $company->id])
            ->expectsOutputToContain('ยังไม่ได้ตั้งบันไดขั้นของแผนอันดับ')
            ->assertExitCode(0);
    }

    public function test_it_reports_binary_agents_who_are_on_no_leg(): void
    {
        $company = Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::Binary->value,
        ]);
        User::factory()->agent()->for($company)->create(['binary_leg' => null]);

        $this->artisan('commission:audit-config', ['--company' => $company->id])
            ->expectsOutputToContain('ตัวแทนที่ยังไม่ได้อยู่ขาไหน')
            ->assertExitCode(0);
    }

    public function test_it_reads_across_tenants_even_though_nobody_is_logged_in(): void
    {
        // TenantScope fails closed for a request with no company — on the
        // console there is no user at all, so a command that forgot
        // withoutGlobalScopes() would report an empty, healthy platform.
        Company::factory()->count(3)->create(['commission_plan_type' => CommissionPlanType::Unilevel->value]);

        $this->artisan('commission:audit-config')
            ->expectsOutputToContain('สรุปรวมทุกบริษัท')
            ->assertExitCode(0);

        $this->assertSame(3, Company::query()->withoutGlobalScopes()->count());
    }
}
