<?php

namespace Tests\Feature\Commission;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Commission\CommissionHouseAccountService;
use App\Services\Commission\CommissionSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-15 — THE LEADERS THIS PLAN PAYS NOTHING, AND NEVER SAYS SO.
 *
 * ADR-035: a cert tier is a GATE on being paid an override. A manager who has
 * never passed one is skipped by CommissionService's chain walk — no error,
 * no log line, no ledger row. The company sets a leader rate in step 4, a
 * sale completes, the seller is paid, and the leader is not.
 *
 * That is the same SHAPE of failure the owner reported this morning
 * ("ค่าคอมตัวแทนไม่ได้คำนวณการตัดให้หัวหน้าทีม"), which turned out to be a
 * company with no manager chain at all — a fact step 4 already showed on
 * screen, which is why it was answerable in minutes. This cause had nothing
 * on screen at all.
 *
 * ── THE AGREEMENT TEST IS THE IMPORTANT ONE HERE ──
 *
 * The warning asks "has this person a certification" with a whereDoesntHave,
 * because the payout-time answer (User::highestPassedCertTier()) is a
 * per-row lookup a screen cannot use without N+1. Two expressions of one
 * rule is the defect shape that once paid a Thai Life rate on an AIA product.
 * test_the_warning_and_the_payout_gate_agree_on_every_person() is the
 * tripwire: if highestPassedCertTier() ever grows a condition — a pass/fail
 * status, an expiry — it fails, and the query has to follow.
 */
class LeaderCertificationWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_leader_with_people_under_them_and_no_certification_is_named(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id, 'name' => 'สมชาย']);
        User::factory()->count(2)->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $warning = $this->warningFor($company);

        $this->assertSame(1, $warning['total']);
        $this->assertSame($leader->id, $warning['leaders'][0]['id']);
        // The count comes with the name. "สมชาย has no certification" is a
        // fact; "สมชาย has no certification and 2 people under him" is a
        // priority.
        $this->assertSame(2, $warning['leaders'][0]['agents_under']);
    }

    public function test_a_certified_leader_is_not_warned_about(): void
    {
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $this->certify($leader, $company);

        $this->assertSame(0, $this->warningFor($company)['total']);
    }

    public function test_an_uncertified_agent_with_nobody_under_them_is_not_warned_about(): void
    {
        /*
         * This is the most common person in any company and the reason the
         * warning is keyed on direct reports rather than on certification
         * alone. Listing every uncertified agent would bury the two or three
         * that cost money in a list of fifty that do not — and a warning
         * nobody can act on is one everybody learns to scroll past.
         */
        $company = Company::factory()->create();
        User::factory()->count(3)->agent()->create(['company_id' => $company->id]);

        $this->assertSame(0, $this->warningFor($company)['total']);
    }

    public function test_the_companys_own_seat_is_never_warned_about(): void
    {
        /*
         * The seat is the ONE deliberate exemption from the cert gate
         * (CommissionService: it cannot sit an exam, so gating it would mean
         * the company is never paid whatever anybody configures). Warning
         * about it would be warning about correct behaviour — and it sits at
         * the top of every chain, so it would appear on every company that
         * turned the feature on.
         */
        $company = Company::factory()->create();
        User::factory()->agent()->create(['company_id' => $company->id]);
        app(CommissionHouseAccountService::class)->create($company);

        $this->assertSame(0, $this->warningFor($company)['total']);
    }

    public function test_another_companys_leaders_are_not_counted(): void
    {
        // BR-6. This number is read on a per-company settings screen; one
        // tenant's uncertified leaders showing up on another's is both a leak
        // and a warning about people the reader cannot certify.
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $theirLeader = User::factory()->agent()->create(['company_id' => $other->id]);
        User::factory()->agent()->create(['company_id' => $other->id, 'manager_id' => $theirLeader->id]);

        $this->assertSame(0, $this->warningFor($company)['total']);
        $this->assertSame(1, $this->warningFor($other)['total']);
    }

    public function test_the_list_is_capped_but_the_count_is_not(): void
    {
        /*
         * "3 of 47" is a different situation from "3", and a payload carrying
         * only the sample would leave the screen able to present the second.
         */
        $company = Company::factory()->create();

        for ($i = 0; $i < 12; $i++) {
            $leader = User::factory()->agent()->create(['company_id' => $company->id]);
            User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        }

        $warning = $this->warningFor($company);

        $this->assertSame(12, $warning['total']);
        $this->assertCount(10, $warning['leaders']);
    }

    public function test_the_biggest_teams_are_listed_first(): void
    {
        // The cap has to drop somebody, so it drops the cheapest. A leader
        // with nine people under them is nine sales' worth of leader
        // commission going nowhere; one with a single recruit is one.
        $company = Company::factory()->create();

        $small = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $small->id]);

        $big = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->count(4)->agent()->create(['company_id' => $company->id, 'manager_id' => $big->id]);

        $this->assertSame($big->id, $this->warningFor($company)['leaders'][0]['id']);
    }

    public function test_the_warning_and_the_payout_gate_agree_on_every_person(): void
    {
        /*
         * THE MIRROR TEST. The screen's whereDoesntHave and the payout's
         * highestPassedCertTier() are two expressions of one rule, and they
         * must return the same verdict for every person or the screen
         * reassures an admin about a leader the payout then skips.
         */
        $company = Company::factory()->create();

        $certified = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $certified->id]);
        $this->certify($certified, $company);

        $uncertified = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $uncertified->id]);

        $warned = collect($this->warningFor($company)['leaders'])->pluck('id')->all();

        foreach ([$certified, $uncertified] as $leader) {
            $paidByTheGate = $leader->highestPassedCertTier() !== null;
            $this->assertSame(
                ! $paidByTheGate,
                in_array($leader->id, $warned, true),
                "the warning and CommissionService's cert gate disagree about user {$leader->id}",
            );
        }
    }

    public function test_the_endpoint_carries_the_warning_to_the_screen(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-settings')
            ->assertOk()
            ->assertJsonPath('data.leaders_missing_certification.total', 1)
            ->assertJsonPath('data.leaders_missing_certification.leaders.0.id', $leader->id);
    }

    public function test_a_super_admin_on_every_company_is_told_nothing_rather_than_something_wrong(): void
    {
        /*
         * "ทุกบริษัท" has no single answer, the same as commission_plan_type
         * on this endpoint. Zero is the honest placeholder here BECAUSE the
         * key is a count of a named set — it is not a claim that no company
         * has the problem, and the screen does not render step 4 in that
         * state at all.
         */
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->getJson('/api/v1/commission-settings')
            ->assertOk()
            ->assertJsonPath('data.leaders_missing_certification.total', 0);
    }

    /** @return array{total: int, leaders: list<array{id: int, name: string, agents_under: int}>} */
    private function warningFor(Company $company): array
    {
        return app(CommissionSettingService::class)->forCompany($company->id)['leaders_missing_certification'];
    }

    private function certify(User $user, Company $company): void
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);

        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);
    }
}
