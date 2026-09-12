<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionBasis;
use App\Enums\CommissionPlanType;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-12 — ONE WRITE DOOR FOR THE TWO COMPANY-LEVEL COMMISSION FIELDS.
 *
 * `commission_basis` and `commission_plan_type` are both columns on
 * `companies`, so the companies resource is the obvious place to accept them,
 * and it did — the plan type since ADR-006, the basis for a few hours on
 * 2026-09-12. Both moved to PUT /commission-settings that day
 * (CommissionSettingEndpointTest covers that door), for three reasons worth
 * keeping written down:
 *
 *  1. TWO DOORS MEANS TWO GATES TO KEEP IN STEP. They agree today —
 *     CompanyPolicy::update and Ability::SettingsCommissionPlanUpdate are
 *     both Super Admin — and there is no mechanism that makes them keep
 *     agreeing. The day one is widened, the other silently becomes a bypass.
 *
 *  2. THEY ARE NOT THE SAME QUESTION. CompanyPolicy::update asks "may you
 *     rename this company, change its bank account, delete it".
 *     SettingsCommissionPlanUpdate asks "may you change who gets paid, and
 *     what they are paid a percentage of". Anybody ever granted the first
 *     would have quietly received the second.
 *
 *  3. THE PLAN TYPE HAD A UI COST ON TOP. Because the write lived here, step
 *     2 of the commission screen — the step whose entire job is "which plan
 *     does this company run" — could only offer a link to /companies. The
 *     owner pressed it, was bounced to another screen, and reported it as
 *     "ทำให้ UI สับสน". Moving the write let the link become a button.
 *
 * This file is the tripwire on the door that was closed. It is deliberately
 * NOT a test that the old path 403s — it does not, and could not: the field
 * is simply no longer validated, so the request succeeds and ignores it. That
 * is the shape a re-introduction would take, and a passing "it is forbidden"
 * test would have missed it entirely.
 */
class CommissionBasisVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_companies_resource_no_longer_writes_either_field(): void
    {
        /*
         * A SUPER ADMIN — the one actor who would succeed if the field were
         * still accepted here. Asserting with a Company Admin would prove
         * nothing: their request is refused by CompanyPolicy::update before
         * validation is ever reached, so it would pass whether the field was
         * accepted or not.
         */
        $company = Company::factory()->create([
            'commission_basis' => CommissionBasis::Price,
            'commission_plan_type' => CommissionPlanType::Unilevel,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/companies/{$company->id}", [
                'name' => 'ชื่อใหม่',
                'commission_basis' => 'pv',
                'commission_plan_type' => 'binary',
            ])
            ->assertOk();

        $this->assertSame('ชื่อใหม่', $company->fresh()->name, 'the rest of the request still works');
        $this->assertSame(
            CommissionBasis::Price,
            $company->fresh()->commission_basis,
            'the basis is not writable through the companies resource — use PUT /commission-settings',
        );
        $this->assertSame(
            CommissionPlanType::Unilevel,
            $company->fresh()->commission_plan_type,
            'nor is the plan type, as of 2026-09-12 — same door, same reason',
        );
    }

    public function test_creating_a_company_ignores_a_supplied_basis(): void
    {
        // The same door, on the way in. A company is created on 'price' (the
        // column default) whatever the request asks for, and switched
        // afterwards through the commission endpoint.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/companies', [
                'name' => 'บริษัทใหม่',
                'slug' => 'new-co',
                'commission_basis' => 'pv',
            ])
            ->assertCreated();

        $this->assertSame(CommissionBasis::Price, Company::where('slug', 'new-co')->sole()->commission_basis);
    }

    public function test_the_companies_resource_still_reports_the_basis(): void
    {
        /*
         * READING it here is fine and stays: CompanyResource describes the
         * company, and the basis is part of that description. What moved is
         * the WRITE. The admin commission screen does not read it from here
         * any more either — see CommissionSettingService's docblock for the
         * quiet failure that dependency invited — so this is a convenience,
         * not a load-bearing path.
         */
        $company = Company::factory()->create(['commission_basis' => CommissionBasis::PointValue]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.commission_basis', 'pv');
    }
}
