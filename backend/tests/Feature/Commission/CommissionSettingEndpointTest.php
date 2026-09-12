<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionBasis;
use App\Enums\CommissionPlanType;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-12 — GET/PUT /commission-settings.
 *
 * ── THE BUG THIS ENDPOINT EXISTS TO MAKE IMPOSSIBLE ──
 *
 * The admin commission screen needs two facts about the company: its
 * commission basis (sale price or PV) and its plan type. Both are columns on
 * `companies`, so the screen first read them with GET /companies/{id} — which
 * works only because CompanyPolicy::view has an own-company clause, under a
 * routes comment that said the whole resource was "Super Admin only end to
 * end".
 *
 * The failure that invited is the quiet kind. Somebody tightens view() to
 * isSuperAdmin() on the strength of that comment; nothing errors, no test
 * fails near the change, and a Company Admin at a PV company opens the screen
 * and is shown 'ราคาขาย' as their basis — the frontend's fallback, rendered
 * with the same confidence as a real answer. They would be reading a wrong
 * statement about how their own agents are paid.
 *
 * So the screen no longer depends on that clause. These tests pin the door it
 * uses instead.
 */
class CommissionSettingEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/commission-settings';

    public function test_a_company_admin_reads_their_own_basis_without_touching_the_companies_resource(): void
    {
        $company = Company::factory()->create([
            'commission_basis' => CommissionBasis::PointValue,
            'commission_plan_type' => CommissionPlanType::Matrix,
        ]);

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $company->id]))
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.commission_basis', 'pv')
            ->assertJsonPath('data.commission_plan_type', 'matrix');
    }

    public function test_a_company_admins_company_id_is_ignored(): void
    {
        // BR-6. The ?company_id= override is Super-Admin-only; for anybody
        // else the server substitutes their own, so asking about a competitor
        // returns the caller's own answer rather than a refusal to think about.
        $mine = Company::factory()->create(['commission_basis' => CommissionBasis::Price]);
        $theirs = Company::factory()->create(['commission_basis' => CommissionBasis::PointValue]);

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $mine->id]))
            ->getJson(self::ENDPOINT."?company_id={$theirs->id}")
            ->assertOk()
            ->assertJsonPath('data.commission_basis', 'price');
    }

    public function test_a_super_admin_may_narrow_to_a_company(): void
    {
        $company = Company::factory()->create(['commission_basis' => CommissionBasis::PointValue]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson(self::ENDPOINT."?company_id={$company->id}")
            ->assertOk()
            ->assertJsonPath('data.commission_basis', 'pv');
    }

    public function test_a_company_that_never_touched_the_setting_reads_as_price(): void
    {
        // Every company that existed before today. 'price' is the default at
        // the column precisely so that nothing about PV is opt-out, and the
        // endpoint must be able to say so without the column being set.
        $company = Company::factory()->create();

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $company->id]))
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.commission_basis', 'price');
    }

    public function test_a_super_admin_may_switch_the_basis(): void
    {
        $company = Company::factory()->create(['commission_basis' => CommissionBasis::Price]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson(self::ENDPOINT, ['company_id' => $company->id, 'commission_basis' => 'pv'])
            ->assertOk()
            ->assertJsonPath('data.commission_basis', 'pv');

        $this->assertSame(CommissionBasis::PointValue, $company->fresh()->commission_basis);
    }

    public function test_a_company_admin_may_not_switch_the_basis(): void
    {
        /*
         * The owner's 2026-09-11 decision, applied through this endpoint's own
         * Ability rather than by borrowing CompanyPolicy::update. Those two
         * answer the same today and are not the same question — routing the
         * basis through company administration would mean anyone ever granted
         * that also, silently, gained the power to change what every
         * percentage in the company is a percentage of.
         */
        $company = Company::factory()->create(['commission_basis' => CommissionBasis::Price]);

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $company->id]))
            ->putJson(self::ENDPOINT, ['commission_basis' => 'pv'])
            ->assertForbidden();

        $this->assertSame(CommissionBasis::Price, $company->fresh()->commission_basis);
    }

    public function test_an_agent_may_not_switch_the_basis(): void
    {
        $company = Company::factory()->create(['commission_basis' => CommissionBasis::Price]);

        $this->actingAs(User::factory()->agent()->create(['company_id' => $company->id]))
            ->putJson(self::ENDPOINT, ['commission_basis' => 'pv'])
            ->assertForbidden();

        $this->assertSame(CommissionBasis::Price, $company->fresh()->commission_basis);
    }

    public function test_the_plan_type_cannot_be_written_here(): void
    {
        // One column, one write door. The plan type is READ by this endpoint
        // because the screen must show it, and changed on the companies
        // resource where it already lives.
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson(self::ENDPOINT, [
                'company_id' => $company->id,
                'commission_basis' => 'pv',
                'commission_plan_type' => 'binary',
            ])
            ->assertOk();

        $this->assertSame(CommissionPlanType::Unilevel, $company->fresh()->commission_plan_type);
    }

    public function test_an_unknown_basis_is_refused(): void
    {
        $company = Company::factory()->create(['commission_basis' => CommissionBasis::Price]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson(self::ENDPOINT, ['company_id' => $company->id, 'commission_basis' => 'points'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('commission_basis');
    }

    public function test_switching_the_basis_is_audited(): void
    {
        /*
         * Section 6 — "record every action that affects money". This is the
         * widest-reaching single field in the commission configuration: one
         * write changes the amount of every future payout on every product.
         * Rows already in the ledger are untouched (BR-4), which is exactly
         * why the moment of the switch has to be recorded somewhere — it is
         * the only thing that explains why two rows for the same product, a
         * week apart, do not agree.
         */
        $company = Company::factory()->create(['commission_basis' => CommissionBasis::Price]);
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->putJson(self::ENDPOINT, ['company_id' => $company->id, 'commission_basis' => 'pv'])
            ->assertOk();

        $log = AuditLog::withoutGlobalScopes()->where('action', 'commission_basis.updated')->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame('price', $log->old_values['commission_basis']);
        $this->assertSame('pv', $log->new_values['commission_basis']);
    }

    public function test_saving_the_same_basis_writes_no_audit_row(): void
    {
        // A no-op is not an event. An audit trail padded with writes that
        // changed nothing is one nobody reads.
        $company = Company::factory()->create(['commission_basis' => CommissionBasis::PointValue]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson(self::ENDPOINT, ['company_id' => $company->id, 'commission_basis' => 'pv'])
            ->assertOk();

        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'commission_basis.updated')->count());
    }

    public function test_a_guest_is_refused(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }
}
