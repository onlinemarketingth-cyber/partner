<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionRateType;
use App\Models\AgentRank;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE LADDER-LEVEL WRITE GUARD (owner choice 3ก, 2026-09-24).
 *
 * Three ladder states make Stairstep money wrong or unpredictable, and all
 * three could be SAVED without a murmur: rate types mixed (the pair is
 * skipped, the manager gets nothing), rates that do not rise with volume
 * (the differential lands at or below zero, so no row is written), and two
 * ranks on one threshold (recalculateRanks() takes the first row the
 * database happens to return).
 *
 * ═══ WHY IT IS "NOT WORSE" AND NOT "VALID" ═══
 *
 * A guard that demanded a valid result would deadlock every company
 * already holding a broken ladder: each edit on the path to fixing it
 * leaves the ladder still broken, so each one would be refused, and the
 * admin would face a form that cannot be saved with no way to begin. The
 * rule is therefore monotone — a finding's count may fall or hold, never
 * rise. test_an_edit_to_an_already_broken_ladder_is_still_allowed below is
 * the case that rule exists for, and the one to keep green.
 *
 * Only a Super Admin may write here at all (see AgentRankTest's own
 * docblock — a rank row carries a rate, and a rate is money).
 */
class AgentRankLadderGuardTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/agent-ranks';

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * Seeded through the MODEL, deliberately — these fixtures represent
     * ladders that predate the guard (or were written by a seeder), which
     * is exactly the population the "not worse" rule exists to unblock.
     */
    private function rank(Company $company, array $over = []): AgentRank
    {
        return AgentRank::factory()->create(array_merge([
            'company_id' => $company->id,
            'name' => 'Rung',
            'volume_threshold' => 0,
            'sort_order' => 1,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 500,
            'is_breakaway_rank' => false,
        ], $over));
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'New rung',
            'volume_threshold' => 10_000_000,
            'sort_order' => 9,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 1_500,
            'is_breakaway_rank' => false,
        ], $over);
    }

    /** A clean two-rung ladder: 5% at ฿0, 12% at ฿50,000. */
    private function cleanLadder(Company $company): void
    {
        $this->rank($company, ['name' => 'Entry', 'volume_threshold' => 0, 'sort_order' => 1, 'rate_value' => 500]);
        $this->rank($company, ['name' => 'Leader', 'volume_threshold' => 5_000_000, 'sort_order' => 2, 'rate_value' => 1_200]);
    }

    // --- Refused ---

    public function test_a_rank_that_would_mix_the_ladders_rate_types_is_refused(): void
    {
        $company = Company::factory()->create();
        $this->cleanLadder($company);

        $this->actingAs($this->superAdmin())
            ->postJson(self::ENDPOINT, $this->payload([
                'company_id' => $company->id,
                'rate_type' => CommissionRateType::FixedSatang->value,
                'rate_value' => 90_000,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate_value');

        $this->assertSame(2, AgentRank::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }

    public function test_a_rank_landing_on_an_occupied_threshold_is_refused(): void
    {
        $company = Company::factory()->create();
        $this->cleanLadder($company);

        $this->actingAs($this->superAdmin())
            ->postJson(self::ENDPOINT, $this->payload([
                'company_id' => $company->id,
                'volume_threshold' => 5_000_000, // already taken by "Leader"
                'rate_value' => 1_800,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate_value');
    }

    public function test_an_update_that_stops_the_rates_rising_is_refused(): void
    {
        $company = Company::factory()->create();
        $this->cleanLadder($company);
        $leader = AgentRank::withoutGlobalScopes()->where('name', 'Leader')->firstOrFail();

        // Dropping the higher rung to 3% would leave someone who sells more
        // earning less — the differential goes negative and nobody is paid.
        $this->actingAs($this->superAdmin())
            ->patchJson(self::ENDPOINT."/{$leader->id}", ['rate_value' => 300])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate_value');

        $this->assertSame(1_200, (int) $leader->fresh()->rate_value);
    }

    public function test_two_rungs_on_the_same_rate_are_refused_as_firmly_as_a_falling_one(): void
    {
        // The differential is exactly 0, and "never a $0 ledger row" turns
        // that into no row at all — the same silence, so the same refusal.
        $company = Company::factory()->create();
        $this->cleanLadder($company);
        $leader = AgentRank::withoutGlobalScopes()->where('name', 'Leader')->firstOrFail();

        $this->actingAs($this->superAdmin())
            ->patchJson(self::ENDPOINT."/{$leader->id}", ['rate_value' => 500])
            ->assertStatus(422);
    }

    // --- Allowed ---

    public function test_an_edit_to_an_already_broken_ladder_is_still_allowed(): void
    {
        /*
         * ═══ THE ANTI-DEADLOCK CASE ═══
         *
         * This company's ladder is already mixed — seeded before the guard
         * existed. Renaming a rung leaves it exactly as broken and not one
         * bit worse, so it goes through. Under a "must be valid" guard this
         * 200 would be a 422, and the admin would have no first move.
         */
        $company = Company::factory()->create();
        $this->rank($company, ['name' => 'Entry', 'volume_threshold' => 0, 'sort_order' => 1, 'rate_value' => 500]);
        $fixed = $this->rank($company, [
            'name' => 'Leader',
            'volume_threshold' => 5_000_000,
            'sort_order' => 2,
            'rate_type' => CommissionRateType::FixedSatang,
            'rate_value' => 90_000,
        ]);

        $this->actingAs($this->superAdmin())
            ->patchJson(self::ENDPOINT."/{$fixed->id}", ['name' => 'ผู้นำ'])
            ->assertOk();

        $this->assertSame('ผู้นำ', $fixed->fresh()->name);
    }

    public function test_an_update_that_repairs_the_ladder_is_allowed(): void
    {
        $company = Company::factory()->create();
        $this->rank($company, ['name' => 'Entry', 'volume_threshold' => 0, 'sort_order' => 1, 'rate_value' => 500]);
        $fixed = $this->rank($company, [
            'name' => 'Leader',
            'volume_threshold' => 5_000_000,
            'sort_order' => 2,
            'rate_type' => CommissionRateType::FixedSatang,
            'rate_value' => 90_000,
        ]);

        $this->actingAs($this->superAdmin())
            ->patchJson(self::ENDPOINT."/{$fixed->id}", [
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 1_200,
            ])
            ->assertOk();

        $this->assertSame(CommissionRateType::Percentage, $fixed->fresh()->rate_type);
    }

    public function test_a_ladder_may_be_built_before_it_has_an_entry_rung(): void
    {
        /*
         * A missing threshold-0 rung is real and reported by the readiness
         * banner — but blocking on it would trap an admin halfway through
         * building a ladder, because the first rank they add cannot also be
         * the one that already exists.
         */
        $company = Company::factory()->create();

        $this->actingAs($this->superAdmin())
            ->postJson(self::ENDPOINT, $this->payload([
                'company_id' => $company->id,
                'volume_threshold' => 5_000_000,
            ]))
            ->assertCreated();
    }

    public function test_a_rank_above_the_breakaway_rung_may_still_be_created(): void
    {
        // Problem 5.5 is a business shape, not a defect — the banner points
        // it out and the owner decides.
        $company = Company::factory()->create();
        $this->rank($company, ['name' => 'Entry', 'volume_threshold' => 0, 'sort_order' => 1, 'rate_value' => 500]);
        $this->rank($company, ['name' => 'Manager', 'volume_threshold' => 5_000_000, 'sort_order' => 2, 'rate_value' => 2_000, 'is_breakaway_rank' => true]);

        $this->actingAs($this->superAdmin())
            ->postJson(self::ENDPOINT, $this->payload([
                'company_id' => $company->id,
                'volume_threshold' => 20_000_000,
                'rate_value' => 2_500,
            ]))
            ->assertCreated();
    }

    // --- BR-6 ---

    public function test_a_ladder_is_only_ever_judged_against_its_own_company(): void
    {
        /*
         * A Super Admin has no ambient tenant scope, so a guard that read
         * "the ranks" without naming a company would measure one tenant's
         * edit against another's ladder — the same class of bug that once
         * let Thai Life's rate appear on AIA's screen (2026-09-12).
         *
         * Company A is percentage-only; company B is empty. A fixed-satang
         * rung for B mixes nothing, and must be accepted.
         */
        $companyA = Company::factory()->create();
        $this->cleanLadder($companyA);
        $companyB = Company::factory()->create();

        $this->actingAs($this->superAdmin())
            ->postJson(self::ENDPOINT, $this->payload([
                'company_id' => $companyB->id,
                'rate_type' => CommissionRateType::FixedSatang->value,
                'rate_value' => 90_000,
            ]))
            ->assertCreated();
    }
}
