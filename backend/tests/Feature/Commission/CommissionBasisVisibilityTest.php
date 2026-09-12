<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionBasis;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-12 — ONE WRITE DOOR FOR THE COMMISSION BASIS.
 *
 * `commission_basis` is a column on `companies`, so the companies resource is
 * the obvious place to accept it, and for a few hours on 2026-09-12 it did.
 * It was moved to PUT /commission-settings the same day
 * (CommissionSettingEndpointTest covers that door), for two reasons worth
 * keeping written down:
 *
 *  1. TWO DOORS MEANS TWO GATES TO KEEP IN STEP. They agree today —
 *     CompanyPolicy::update and Ability::SettingsCommissionBasisUpdate are
 *     both Super Admin — and there is no mechanism that makes them keep
 *     agreeing. The day one is widened, the other silently becomes a bypass.
 *
 *  2. THEY ARE NOT THE SAME QUESTION. CompanyPolicy::update asks "may you
 *     rename this company, change its bank account, delete it".
 *     SettingsCommissionBasisUpdate asks "may you change what every
 *     percentage in this company is a percentage of". Anybody ever granted
 *     the first would have quietly received the second.
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

    public function test_the_companies_resource_no_longer_writes_the_basis(): void
    {
        /*
         * A SUPER ADMIN — the one actor who would succeed if the field were
         * still accepted here. Asserting with a Company Admin would prove
         * nothing: their request is refused by CompanyPolicy::update before
         * validation is ever reached, so it would pass whether the field was
         * accepted or not.
         */
        $company = Company::factory()->create(['commission_basis' => CommissionBasis::Price]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/companies/{$company->id}", [
                'name' => 'ชื่อใหม่',
                'commission_basis' => 'pv',
            ])
            ->assertOk();

        $this->assertSame('ชื่อใหม่', $company->fresh()->name, 'the rest of the request still works');
        $this->assertSame(
            CommissionBasis::Price,
            $company->fresh()->commission_basis,
            'the basis is not writable through the companies resource — use PUT /commission-settings',
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
