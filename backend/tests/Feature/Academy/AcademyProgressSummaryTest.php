<?php

namespace Tests\Feature\Academy;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleCompletion;
use App\Models\ModuleLesson;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-152 — GET /api/v1/academy-progress-summary.
 *
 * The point of the endpoint is that NO FRACTION IS EVER COMPUTED FROM A PAGE,
 * so the tests that matter most are the ones that push the underlying data past
 * a page boundary (16 Sections, 20+ completions) and then assert the arithmetic
 * anyway — see the "TRUNCATION" section. Those are the regressions; everything
 * else is scaffolding around them.
 *
 * The three non-negotiables have their own sections at the bottom:
 * tenant isolation (BR-6), "zero completions still appears as 0/N", and the
 * treatment of completions on lessons that were later made optional.
 */
class AcademyProgressSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/academy-progress-summary';

    // =================================================================
    // Fixtures
    // =================================================================

    /**
     * @param  array<int, array<string, mixed>>  $lessonOverrides
     */
    private function makeSection(Company $company, array $lessonOverrides = [], array $moduleOverrides = []): Module
    {
        $module = Module::factory()->for($company)->create($moduleOverrides + [
            'cert_tier_id' => CertTier::factory()->create()->id,
        ]);

        foreach ($lessonOverrides as $index => $overrides) {
            ModuleLesson::factory()->for($company)->create($overrides + [
                'module_id' => $module->id,
                'sort_order' => $index,
            ]);
        }

        return $module;
    }

    private function complete(User $agent, ModuleLesson $lesson): ModuleCompletion
    {
        // Written directly rather than through the API on purpose: this suite is
        // about how completions are COUNTED, and driving each one through the
        // ADR-028/029/031 gates would make every arithmetic test also a test of
        // the write path (and would make it impossible to fixture a
        // grandfathered row at all).
        return ModuleCompletion::factory()->create([
            'company_id' => $lesson->company_id,
            'user_id' => $agent->id,
            'module_lesson_id' => $lesson->id,
            'completed_at' => now(),
        ]);
    }

    private function grantTier(User $agent, CertTier $tier): void
    {
        UserCertification::create([
            'company_id' => $agent->company_id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function agentRow(array $payload, int $userId): array
    {
        foreach ($payload['data'] as $row) {
            if ($row['user_id'] === $userId) {
                return $row;
            }
        }

        $this->fail("Agent {$userId} is missing from the progress summary entirely.");
    }

    // =================================================================
    // Authorization + BR-6
    // =================================================================

    public function test_company_admin_can_read_the_progress_summary_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('summary.company_id', $company->id);
    }

    public function test_an_agent_may_not_read_other_peoples_learning_data(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_a_guest_gets_401(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_super_admin_must_name_a_company(): void
    {
        // TenantScope does not constrain a Super Admin, so an unnamed company
        // would silently mean "every tenant on the platform" (BR-6).
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->getJson(self::ENDPOINT)
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_id');
    }

    public function test_super_admin_reads_only_the_company_they_named(): void
    {
        $thaiLife = Company::factory()->create();
        $other = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $mine = User::factory()->agent()->create(['company_id' => $thaiLife->id]);
        $theirs = User::factory()->agent()->create(['company_id' => $other->id]);

        $response = $this->actingAs($superAdmin)
            ->getJson(self::ENDPOINT.'?company_id='.$thaiLife->id)
            ->assertOk();

        $userIds = array_column($response->json('data'), 'user_id');

        $this->assertContains($mine->id, $userIds);
        $this->assertNotContains($theirs->id, $userIds);
    }

    // =================================================================
    // NON-NEGOTIABLE 1 — tenant isolation (BR-6, §5 rule 5)
    // =================================================================

    public function test_a_company_admin_naming_another_company_is_refused_not_silently_coerced(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);

        $this->actingAs($admin)
            ->getJson(self::ENDPOINT.'?company_id='.$otherCompany->id)
            ->assertForbidden();
    }

    public function test_no_agent_lesson_or_completion_from_another_company_ever_appears(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);

        $mine = User::factory()->agent()->create(['company_id' => $ownCompany->id]);
        $this->makeSection($ownCompany, [[], []]);

        // A whole parallel course in another tenant, fully completed. None of
        // it may reach either side of my fractions.
        $theirAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);
        $theirSection = $this->makeSection($otherCompany, [[], [], []]);
        foreach ($theirSection->lessons as $lesson) {
            $this->complete($theirAgent, $lesson);
        }

        $payload = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json();

        $userIds = array_column($payload['data'], 'user_id');
        $this->assertSame([$mine->id], $userIds);

        $this->assertSame(2, $payload['summary']['required_lesson_count']);
        $this->assertCount(1, $payload['summary']['sections']);
        $this->assertSame(0, $this->agentRow($payload, $mine->id)['completed_required_count']);
        $this->assertSame(1, $payload['summary']['agent_count']);
    }

    // =================================================================
    // NON-NEGOTIABLE 2 — nobody is missing
    // =================================================================

    public function test_an_agent_with_zero_completions_appears_with_a_zero_numerator(): void
    {
        // "A dashboard that silently omits the people who have done nothing is
        // worse than no dashboard — those are exactly the rows an admin is
        // looking for." The roster comes from `users`, not from completions,
        // which is what makes this structural rather than a special case.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $idle = User::factory()->agent()->create(['company_id' => $company->id]);

        $section = $this->makeSection($company, [[], [], []]);

        $payload = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json();
        $row = $this->agentRow($payload, $idle->id);

        $this->assertSame(0, $row['completed_required_count']);
        $this->assertSame(3, $row['required_lesson_count']);
        $this->assertSame([], $row['completed_lesson_ids']);
        $this->assertSame([], $row['cert_tiers_passed']);

        // ...and the Section they have not touched is present as 0/3, not absent.
        $this->assertCount(1, $row['sections']);
        $this->assertSame($section->id, $row['sections'][0]['module_id']);
        $this->assertSame(0, $row['sections'][0]['completed_required_count']);
        $this->assertSame(3, $row['sections'][0]['required_lesson_count']);
    }

    public function test_an_agent_who_has_never_touched_the_academy_still_carries_their_cert_badges(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create(['key' => 'basic', 'name' => 'Basic']);

        // BR-1 admin override (TASK-058): a manually granted tier with no
        // completions behind it at all.
        $this->grantTier($agent, $tier);

        $row = $this->agentRow(
            $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json(),
            $agent->id,
        );

        $this->assertCount(1, $row['cert_tiers_passed']);
        $this->assertSame('basic', $row['cert_tiers_passed'][0]['key']);
    }

    // =================================================================
    // NON-NEGOTIABLE 3 — optional and draft lessons (ADR-031 §2.4)
    // =================================================================

    public function test_optional_and_draft_lessons_are_excluded_from_the_denominator(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->makeSection($company, [
            ['is_published' => true, 'is_optional' => false],
            ['is_published' => true, 'is_optional' => false],
            ['is_published' => true, 'is_optional' => true],   // supplementary
            ['is_published' => false, 'is_optional' => false], // draft
        ]);

        $payload = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json();

        // 4 lessons exist; only 2 may be divided by.
        $this->assertSame(2, $payload['summary']['required_lesson_count']);
        $this->assertSame(2, $payload['summary']['sections'][0]['required_lesson_count']);
        $this->assertSame(1, $payload['summary']['sections'][0]['optional_lesson_count']);
        $this->assertSame(4, $payload['summary']['sections'][0]['lesson_count']);
        $this->assertSame(2, $this->agentRow($payload, $agent->id)['required_lesson_count']);

        // The outline still SHOWS all four (the admin needs to see the draft) —
        // it is the counts that exclude them.
        $this->assertCount(4, $payload['summary']['sections'][0]['lessons']);
    }

    public function test_a_completion_on_a_lesson_that_was_later_made_optional_never_pushes_the_fraction_above_one(): void
    {
        /*
         * THE DECISION, asserted rather than left implicit.
         *
         * An agent completes 3 of 3 lessons. An admin then marks one of them
         * optional. The completion row survives untouched (append-only, and
         * grandfathering means it is never re-evaluated), but it must NOT stay
         * in the required numerator — the denominator just dropped to 2, and
         * "3/2" would tell the admin the dashboard is broken.
         *
         * It is not discarded either: it moves to completed_optional_count, so
         * the work is still visible and the total does not appear to fall
         * overnight for a reason nobody can see.
         */
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $section = $this->makeSection($company, [[], [], []]);
        foreach ($section->lessons as $lesson) {
            $this->complete($agent, $lesson);
        }

        $before = $this->agentRow(
            $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json(),
            $agent->id,
        );
        $this->assertSame(3, $before['completed_required_count']);
        $this->assertSame(3, $before['required_lesson_count']);
        $this->assertSame(0, $before['completed_optional_count']);

        $section->lessons()->first()->update(['is_optional' => true]);

        $after = $this->agentRow(
            $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json(),
            $agent->id,
        );

        $this->assertSame(2, $after['required_lesson_count']);
        $this->assertSame(2, $after['completed_required_count'], 'A required fraction must never exceed 1.');
        $this->assertSame(1, $after['completed_optional_count'], 'The completion must still be visible somewhere.');
        // The completion row itself is untouched — nothing here deletes history.
        $this->assertCount(3, $after['completed_lesson_ids']);
    }

    public function test_a_grandfathered_completion_counts_like_any_other(): void
    {
        // ADR-028 §2.3 guard 1 — a completion recorded before today's gate
        // existed is an ordinary row and is never re-evaluated. This endpoint
        // reads rows; it must not grow an opinion about how they were earned.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $section = $this->makeSection($company, [
            ['content_type' => 'video', 'source_type' => 'upload', 'duration_seconds' => 600],
        ]);
        $lesson = $section->lessons->first();

        // No module_lesson_progress row at all — under today's rules this agent
        // could not earn this completion. They already have it.
        $completion = $this->complete($agent, $lesson);
        $completion->forceFill(['completed_at' => now()->subYear()])->save();

        $row = $this->agentRow(
            $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json(),
            $agent->id,
        );

        $this->assertSame(1, $row['completed_required_count']);
    }

    public function test_a_completion_on_a_soft_deleted_lesson_disappears_from_both_sides(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $section = $this->makeSection($company, [[], []]);
        foreach ($section->lessons as $lesson) {
            $this->complete($agent, $lesson);
        }

        $section->lessons()->first()->delete();

        $payload = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json();
        $row = $this->agentRow($payload, $agent->id);

        $this->assertSame(1, $row['required_lesson_count']);
        $this->assertSame(1, $row['completed_required_count']);
        $this->assertSame(0, $row['completed_optional_count']);
    }

    // =================================================================
    // TRUNCATION — the actual bug. Everything here crosses a page boundary.
    // =================================================================

    public function test_the_denominator_survives_more_sections_than_a_page_of_modules_holds(): void
    {
        // GET /modules paginates at 15. The old client-side denominator was
        // `sum(lessons.length)` over ONE page of that endpoint, so a 16th
        // Section simply did not exist as far as the fraction was concerned.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        for ($i = 0; $i < 20; $i++) {
            $this->makeSection($company, [[], []], ['sort_order' => $i]);
        }

        $payload = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json();

        $this->assertCount(20, $payload['summary']['sections']);
        $this->assertSame(40, $payload['summary']['required_lesson_count']);
        $this->assertSame(40, $this->agentRow($payload, $agent->id)['required_lesson_count']);
        $this->assertCount(20, $this->agentRow($payload, $agent->id)['sections']);
    }

    public function test_the_numerator_survives_more_completions_than_a_page_of_completions_holds(): void
    {
        // GET /module-completions paginates at 15 ACROSS THE WHOLE COMPANY, so
        // with two agents and 40 completions the old numerator was whichever 15
        // rows happened to sort first.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $busy = User::factory()->agent()->create(['company_id' => $company->id]);
        $busier = User::factory()->agent()->create(['company_id' => $company->id]);

        $lessons = [];
        for ($i = 0; $i < 4; $i++) {
            $section = $this->makeSection($company, array_fill(0, 5, []), ['sort_order' => $i]);
            foreach ($section->lessons as $lesson) {
                $lessons[] = $lesson;
            }
        }

        $this->assertCount(20, $lessons);

        foreach ($lessons as $lesson) {
            $this->complete($busy, $lesson);
            $this->complete($busier, $lesson);
        }

        $payload = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json();

        $this->assertSame(20, $this->agentRow($payload, $busy->id)['completed_required_count']);
        $this->assertSame(20, $this->agentRow($payload, $busier->id)['completed_required_count']);
        $this->assertSame(20, $this->agentRow($payload, $busy->id)['required_lesson_count']);
    }

    public function test_paginating_the_agent_list_never_truncates_a_row_it_returns(): void
    {
        // The agent LIST may be paged — that is a list of people and shortening
        // it is honest. Every fraction on a returned row must still be complete.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $section = $this->makeSection($company, [[], [], []]);

        $agents = User::factory()->count(5)->agent()->create(['company_id' => $company->id]);
        foreach ($agents as $agent) {
            foreach ($section->lessons as $lesson) {
                $this->complete($agent, $lesson);
            }
        }

        $payload = $this->actingAs($admin)->getJson(self::ENDPOINT.'?per_page=2')->assertOk()->json();

        $this->assertCount(2, $payload['data']);
        $this->assertSame(5, $payload['meta']['total']);
        $this->assertSame(3, $payload['meta']['last_page']);

        foreach ($payload['data'] as $row) {
            $this->assertSame(3, $row['completed_required_count']);
            $this->assertSame(3, $row['required_lesson_count']);
            $this->assertCount(3, $row['completed_lesson_ids']);
        }

        // ...and the company-wide roll-up is measured against all 5, not the 2
        // on this page.
        $this->assertSame(5, $payload['summary']['agent_count']);
        $this->assertSame(5, $payload['summary']['sections'][0]['agents_completed']);
    }

    // =================================================================
    // The per-Section roll-up across agents
    // =================================================================

    public function test_the_section_rollup_counts_only_agents_who_finished_every_required_lesson(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $section = $this->makeSection($company, [
            ['is_published' => true, 'is_optional' => false],
            ['is_published' => true, 'is_optional' => false],
            ['is_published' => true, 'is_optional' => true],
        ]);
        $required = $section->lessons->where('is_optional', false)->values();
        $optional = $section->lessons->firstWhere('is_optional', true);

        $finished = User::factory()->agent()->create(['company_id' => $company->id]);
        $halfway = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $company->id]); // untouched

        $this->complete($finished, $required[0]);
        $this->complete($finished, $required[1]);
        $this->complete($halfway, $required[0]);
        // An optional lesson can never carry someone over the line.
        $this->complete($halfway, $optional);

        $rollup = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()
            ->json('summary.sections.0');

        $this->assertSame(1, $rollup['agents_completed']);
        $this->assertSame(3, $rollup['completed_required_total']);
    }

    public function test_a_section_with_no_required_lessons_is_vacuously_complete_for_everyone(): void
    {
        // 0/0. The per-agent row reads 0/0, so the roll-up must not read
        // "0 of 3 agents finished" beside it and contradict itself.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        User::factory()->count(3)->agent()->create(['company_id' => $company->id]);

        $this->makeSection($company, [['is_published' => true, 'is_optional' => true]]);

        $rollup = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()
            ->json('summary.sections.0');

        $this->assertSame(0, $rollup['required_lesson_count']);
        $this->assertSame(3, $rollup['agents_completed']);
    }

    public function test_an_empty_section_still_appears_in_the_outline(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->makeSection($company, []);

        $sections = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json('summary.sections');

        $this->assertCount(1, $sections);
        $this->assertSame(0, $sections[0]['lesson_count']);
        $this->assertSame([], $sections[0]['lessons']);
    }

    // =================================================================
    // Roster shape
    // =================================================================

    public function test_only_agents_appear_on_the_roster(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $userIds = array_column(
            $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json('data'),
            'user_id',
        );

        // The Admin reading the screen is not a learner on it.
        $this->assertSame([$agent->id], $userIds);
    }

    public function test_a_soft_deleted_agent_drops_off_the_roster(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $leaver = User::factory()->agent()->create(['company_id' => $company->id]);
        $stayer = User::factory()->agent()->create(['company_id' => $company->id]);

        $leaver->delete();

        $userIds = array_column(
            $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json('data'),
            'user_id',
        );

        $this->assertSame([$stayer->id], $userIds);
    }

    public function test_the_search_box_filters_the_roster_but_not_the_company_rollup(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $target = User::factory()->agent()->create([
            'company_id' => $company->id,
            'first_name' => 'Somchai',
            'last_name' => 'Jaidee',
        ]);
        User::factory()->count(3)->agent()->create(['company_id' => $company->id]);

        $payload = $this->actingAs($admin)->getJson(self::ENDPOINT.'?q=Somchai')->assertOk()->json();

        $this->assertCount(1, $payload['data']);
        $this->assertSame($target->id, $payload['data'][0]['user_id']);
        // The roll-up describes the company, so it must not move while an admin
        // types a name.
        $this->assertSame(4, $payload['summary']['agent_count']);
    }

    public function test_national_id_is_never_returned_by_this_endpoint(): void
    {
        // PDPA (CLAUDE.md §6) — the reveal gate for an identity document lives
        // in UserResource. An aggregate progress endpoint must not grow a
        // second copy of it, so it carries no document number at all.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        User::factory()->agent()->create([
            'company_id' => $company->id,
            'national_id' => '1234567890123',
        ]);

        $response = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk();

        $this->assertStringNotContainsString('1234567890123', $response->getContent());
        $this->assertArrayNotHasKey('national_id', $response->json('data.0'));
        $this->assertArrayNotHasKey('national_id_masked', $response->json('data.0'));
    }
}
