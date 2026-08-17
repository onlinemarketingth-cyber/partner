<?php

namespace Tests\Feature\Academy;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleLesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-155 — A DRAFT IS NOT PART OF THE COURSE.
 *
 * Before this, `GET /api/v1/modules` applied no `is_published` filter of any
 * kind. Draft Sections AND draft lessons were serialised to every Agent, and
 * the only thing hiding the draft lessons was `visibleLessons()` in the Vue
 * client — while the draft SECTION's header rendered regardless. `stream()`
 * would hand over a draft lesson's bytes to anyone who guessed its id.
 *
 * That is the same "a client-side lock is decoration" failure ADR-031 §2.2
 * already rejected for sequential locks, on routes that feed the BR-1
 * certification gate.
 *
 * Two mechanisms, tested separately below because they answer different
 * questions:
 *
 *   HIDDEN  — ModuleController keeps drafts out of the list for Agents.
 *   REFUSED — LessonAccessGate answers a guessed id on stream / completion /
 *             progress / quiz-attempt.
 *
 * And one consequence, tested at the bottom: the progress denominator moved
 * with it, numerator and denominator together.
 */
class AcademyDraftVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One company with a published Section and a draft Section, each holding
     * one published and one draft lesson.
     *
     * @return array{company: Company, agent: User, admin: User, live: Module, draft: Module, liveLesson: ModuleLesson, draftLessonInLiveSection: ModuleLesson, lessonInDraftSection: ModuleLesson}
     */
    private function scenario(): array
    {
        $company = Company::factory()->create();
        $tier = CertTier::factory()->create();

        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $live = Module::factory()->for($company)->create([
            'cert_tier_id' => $tier->id,
            'is_published' => true,
            'sort_order' => 0,
        ]);

        $draft = Module::factory()->for($company)->create([
            'cert_tier_id' => $tier->id,
            'is_published' => false,
            'sort_order' => 1,
        ]);

        $lesson = fn (Module $m, bool $published, int $sort) => ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $m->id,
            'content_type' => 'video',
            'source_type' => null,
            'is_published' => $published,
            'is_optional' => false,
            'sort_order' => $sort,
        ]);

        return [
            'company' => $company,
            'agent' => $agent,
            'admin' => $admin,
            'live' => $live,
            'draft' => $draft,
            'liveLesson' => $lesson($live, true, 0),
            'draftLessonInLiveSection' => $lesson($live, false, 1),
            'lessonInDraftSection' => $lesson($draft, true, 0),
        ];
    }

    // =================================================================
    // HIDDEN — the list
    // =================================================================

    public function test_an_agent_does_not_receive_a_draft_section(): void
    {
        $s = $this->scenario();

        $ids = collect(
            $this->actingAs($s['agent'])->getJson('/api/v1/modules')->assertOk()->json('data')
        )->pluck('id');

        $this->assertTrue($ids->contains($s['live']->id));
        $this->assertFalse(
            $ids->contains($s['draft']->id),
            'A draft Section was served to an Agent — TASK-155.'
        );
    }

    public function test_an_agent_does_not_receive_a_draft_lesson_inside_a_published_section(): void
    {
        $s = $this->scenario();

        $section = collect(
            $this->actingAs($s['agent'])->getJson('/api/v1/modules')->assertOk()->json('data')
        )->firstWhere('id', $s['live']->id);

        $lessonIds = collect($section['lessons'])->pluck('id');

        $this->assertTrue($lessonIds->contains($s['liveLesson']->id));
        $this->assertFalse(
            $lessonIds->contains($s['draftLessonInLiveSection']->id),
            'A draft lesson was served to an Agent — it was only ever hidden client-side.'
        );
    }

    public function test_an_admin_still_receives_drafts_because_they_are_authoring_them(): void
    {
        $s = $this->scenario();

        $data = collect(
            $this->actingAs($s['admin'])->getJson('/api/v1/modules')->assertOk()->json('data')
        );

        $this->assertTrue(
            $data->pluck('id')->contains($s['draft']->id),
            'The Admin course builder cannot show a draft Section it is not sent.'
        );

        $live = $data->firstWhere('id', $s['live']->id);
        $this->assertTrue(collect($live['lessons'])->pluck('id')->contains($s['draftLessonInLiveSection']->id));
    }

    public function test_show_returns_404_to_an_agent_asking_for_a_draft_section_by_id(): void
    {
        $s = $this->scenario();

        // 404, not 403: to an Agent it does not exist. Distinguishing the two
        // is the IDOR-adjacent leak CLAUDE.md §5.5 warns about.
        $this->actingAs($s['agent'])
            ->getJson("/api/v1/modules/{$s['draft']->id}")
            ->assertNotFound();

        $this->actingAs($s['admin'])
            ->getJson("/api/v1/modules/{$s['draft']->id}")
            ->assertOk();
    }

    // =================================================================
    // REFUSED — a guessed id
    // =================================================================

    public function test_an_agent_cannot_stream_a_draft_lesson(): void
    {
        Storage::fake('local');

        $s = $this->scenario();

        $path = 'academy/'.Str::uuid().'.mp4';
        Storage::disk('local')->put($path, 'bytes');

        $s['draftLessonInLiveSection']->update([
            'source_type' => 'upload',
            'content_ref' => $path,
        ]);

        $this->actingAs($s['agent'])
            ->get("/api/v1/module-lessons/{$s['draftLessonInLiveSection']->id}/stream")
            ->assertForbidden();
    }

    public function test_an_agent_cannot_complete_a_draft_lesson(): void
    {
        $s = $this->scenario();

        $this->actingAs($s['agent'])
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $s['draftLessonInLiveSection']->id])
            ->assertUnprocessable();
    }

    public function test_a_published_lesson_inside_a_draft_section_is_refused_too(): void
    {
        // The Section's flag counts as much as the lesson's. An admin who
        // unpublishes a Section means its contents are out of the course; if
        // only the lesson flag were read they would have to unpublish every
        // lesson individually, and would reasonably assume they had not.
        $s = $this->scenario();

        $this->actingAs($s['agent'])
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $s['lessonInDraftSection']->id])
            ->assertUnprocessable();
    }

    public function test_the_refusal_message_never_mentions_that_a_draft_exists(): void
    {
        $s = $this->scenario();

        $message = $this->actingAs($s['agent'])
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $s['draftLessonInLiveSection']->id])
            ->assertUnprocessable()
            ->json('errors.module_lesson_id.0');

        $this->assertStringNotContainsString('ฉบับร่าง', (string) $message);
        $this->assertStringNotContainsString('draft', Str::lower((string) $message));
    }

    public function test_an_admin_is_never_locked_out_of_their_own_draft(): void
    {
        Storage::fake('local');

        $s = $this->scenario();

        $path = 'academy/'.Str::uuid().'.mp4';
        Storage::disk('local')->put($path, 'bytes');

        $s['draftLessonInLiveSection']->update([
            'source_type' => 'upload',
            'content_ref' => $path,
        ]);

        // Previewing what you are drafting is the whole point of the
        // isSuperAdmin/isCompanyAdmin exemption in LessonAccessGate.
        $this->actingAs($s['admin'])
            ->get("/api/v1/module-lessons/{$s['draftLessonInLiveSection']->id}/stream")
            ->assertOk();
    }

    // =================================================================
    // The denominator moved with it
    // =================================================================

    public function test_a_draft_section_contributes_nothing_to_the_progress_denominator(): void
    {
        $s = $this->scenario();

        $summary = $this->actingAs($s['admin'])
            ->getJson('/api/v1/academy-progress-summary')
            ->assertOk()
            ->json();

        // One published, non-optional lesson inside one published Section.
        // The draft Section's published lesson and the published Section's
        // draft lesson are both excluded.
        $this->assertSame(1, $summary['summary']['required_lesson_count']);

        $draftRow = collect($summary['summary']['sections'])->firstWhere('id', $s['draft']->id);

        $this->assertNotNull($draftRow, 'The draft Section is still REPORTED — the admin outline needs it.');
        $this->assertSame(0, $draftRow['required_lesson_count']);
        $this->assertFalse($draftRow['is_published']);
    }
}
