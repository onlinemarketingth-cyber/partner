<?php

namespace Tests\Feature\Academy;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleLesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-09-17 — the signed stream a `<video>` element can actually fetch.
 *
 * ── THE BUG ──
 *
 * Owner, with a Network tab open: "frontend รัน clip ไม่ได้" —
 * GET /module-lessons/5/stream → 401, on a page where the learner was
 * plainly logged in (notifications, avatar, everything else loading).
 *
 * The agent portal authenticates with a BEARER TOKEN (ADR-039). A `<video
 * src>` element cannot carry an Authorization header, and there was no
 * session cookie left for its `crossorigin="use-credentials"` to send. So
 * the element asked an auth:sanctum route anonymously, every time, and the
 * player's retry button could never succeed because a 401 is an answer
 * rather than a blip.
 *
 * Images and PDFs survived the same migration because JS fetches them and
 * can set the header (fixed 2026-09-04). Video cannot: the whole point is
 * that the BROWSER issues the ranged GETs, or seeking to 18:42 means
 * downloading 200 MB first (ADR-028 §2.5).
 *
 * ── WHAT THIS FILE IS DEFENDING ──
 *
 * The authorization moved into the URL, and that is exactly the kind of
 * move that goes wrong quietly. So every check the authenticated route
 * runs is asserted here AGAIN on the signed one:
 *
 *   · an unsigned or edited URL is refused           (the signature works)
 *   · `u` cannot be swapped for another learner      (it is inside the HMAC)
 *   · another company's learner is still refused     (the Module policy)
 *   · a locked lesson is still refused               (LessonAccessGate)
 *   · an expired URL is refused                      (the 2-hour life)
 *   · ranged GETs still work                         (the reason for all this)
 *
 * If any one of those stops holding, this route is a public file server for
 * the whole Academy and nothing on any screen would say so.
 */
class LessonInlineStreamTest extends TestCase
{
    use RefreshDatabase;

    // 26 bytes, so byte offsets read plainly in the range assertions.
    private const BODY = 'abcdefghijklmnopqrstuvwxyz';

    /** @return array{company: Company, module: Module, lesson: ModuleLesson} */
    private function makeVideoLesson(): array
    {
        $company = Company::factory()->create();
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $path = "academy-modules/{$company->id}/".Str::uuid()->toString().'.mp4';
        Storage::disk('local')->put($path, self::BODY);

        $lesson = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $module->id,
            'content_type' => 'video',
            'source_type' => 'upload',
            'content_ref' => $path,
        ]);

        return compact('company', 'module', 'lesson');
    }

    /** The URL the resource would hand this learner. */
    private function signedUrlFor(ModuleLesson $lesson, User $learner, ?int $ttlHours = 2): string
    {
        return URL::temporarySignedRoute(
            'module-lessons.inline-stream',
            now()->addHours($ttlHours),
            ['moduleLesson' => $lesson->id, 'u' => $learner->id],
        );
    }

    /** The path+query, which is what the test client wants. */
    private function pathOf(string $url): string
    {
        return str_replace(config('app.url'), '', $url);
    }

    // ── It works at all ────────────────────────────────────────────────

    public function test_a_signed_url_plays_without_any_credentials(): void
    {
        /*
         * THE TEST THE WHOLE CHANGE EXISTS FOR. No actingAs, no header, no
         * cookie — exactly what a `<video src>` sends — and it must return
         * the file rather than 401.
         */
        Storage::fake('local');
        $s = $this->makeVideoLesson();
        $agent = User::factory()->agent()->create(['company_id' => $s['company']->id]);

        $response = $this->get($this->pathOf($this->signedUrlFor($s['lesson'], $agent)));

        $response->assertOk()
            // Without this a browser will not even attempt to seek.
            ->assertHeader('Accept-Ranges', 'bytes');

        $this->assertSame(self::BODY, $response->streamedContent());
    }

    public function test_ranged_gets_still_work_which_is_the_entire_point(): void
    {
        // A blob would have made authorization easy and seeking useless.
        Storage::fake('local');
        $s = $this->makeVideoLesson();
        $agent = User::factory()->agent()->create(['company_id' => $s['company']->id]);

        $response = $this->withHeaders(['Range' => 'bytes=5-9'])
            ->get($this->pathOf($this->signedUrlFor($s['lesson'], $agent)));

        $response->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 5-9/26');

        $this->assertSame('fghij', $response->streamedContent());
    }

    public function test_it_is_served_inline_even_for_a_downloadable_lesson(): void
    {
        /*
         * The authenticated route serves `attachment` for a downloadable file
         * and honours `?inline=1` as an override. This route has no such
         * branch — a Content-Disposition of attachment is precisely what
         * stops a video playing, and feeding a player is all this route does.
         */
        Storage::fake('local');
        $s = $this->makeVideoLesson();
        $s['lesson']->update(['is_downloadable' => true]);
        $agent = User::factory()->agent()->create(['company_id' => $s['company']->id]);

        $response = $this->get($this->pathOf($this->signedUrlFor($s['lesson'], $agent)))->assertOk();

        // The header carries a filename too, so the assertion is on the
        // disposition itself rather than on the whole string.
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
    }

    // ── The signature is load-bearing ──────────────────────────────────

    public function test_the_unsigned_url_is_refused(): void
    {
        Storage::fake('local');
        $s = $this->makeVideoLesson();

        $this->get("/api/v1/module-lessons/{$s['lesson']->id}/inline-stream?u=1")
            ->assertForbidden();
    }

    public function test_the_learner_id_cannot_be_edited_to_somebody_else(): void
    {
        /*
         * THE ATTACK THIS ROUTE WOULD OTHERWISE INVITE. `u` decides who the
         * server acts as, so if it were merely a query parameter, a learner
         * holding one valid link could read every other learner's lessons by
         * counting upwards.
         *
         * It is inside the HMAC, so changing it invalidates the whole URL.
         */
        Storage::fake('local');
        $s = $this->makeVideoLesson();
        $mine = User::factory()->agent()->create(['company_id' => $s['company']->id]);
        $somebodyElse = User::factory()->agent()->create(['company_id' => $s['company']->id]);

        $signed = $this->pathOf($this->signedUrlFor($s['lesson'], $mine));
        $tampered = str_replace("u={$mine->id}", "u={$somebodyElse->id}", $signed);

        $this->assertNotSame($signed, $tampered, 'the fixture must actually change the id');

        $this->get($tampered)->assertForbidden();
    }

    public function test_the_lesson_id_cannot_be_swapped_for_another_lesson(): void
    {
        // The other half of the same property: one signed link opens ONE
        // lesson, not the Academy.
        Storage::fake('local');
        $mine = $this->makeVideoLesson();
        $other = $this->makeVideoLesson();
        $agent = User::factory()->agent()->create(['company_id' => $mine['company']->id]);

        $signed = $this->pathOf($this->signedUrlFor($mine['lesson'], $agent));
        $swapped = str_replace(
            "/module-lessons/{$mine['lesson']->id}/",
            "/module-lessons/{$other['lesson']->id}/",
            $signed,
        );

        $this->get($swapped)->assertForbidden();
    }

    public function test_an_expired_url_is_refused(): void
    {
        /*
         * The 2-hour life is the mitigation for the one thing a signed URL
         * genuinely trades away: for as long as it is valid, whoever holds
         * the string can watch. If expiry did not bite, that trade would be
         * permanent.
         */
        Storage::fake('local');
        $s = $this->makeVideoLesson();
        $agent = User::factory()->agent()->create(['company_id' => $s['company']->id]);

        $url = $this->pathOf($this->signedUrlFor($s['lesson'], $agent));

        $this->travel(3)->hours();

        $this->get($url)->assertForbidden();
    }

    public function test_a_learner_who_no_longer_exists_is_refused_not_waved_through(): void
    {
        /*
         * LessonAccessGate::reasonFor() answers NULL — "not locked" — when
         * handed a null learner. That is right for its own callers and would
         * be a hole here: an unresolvable `u` would sail straight past the
         * lock check. So the absence is caught at the door instead.
         */
        Storage::fake('local');
        $s = $this->makeVideoLesson();
        $agent = User::factory()->agent()->create(['company_id' => $s['company']->id]);

        $url = $this->pathOf($this->signedUrlFor($s['lesson'], $agent));

        $agent->forceDelete();

        $this->get($url)->assertForbidden();
    }

    // ── Every gate the authenticated route runs ────────────────────────

    public function test_another_companys_learner_is_still_refused(): void
    {
        // BR-6, on a route with no authenticated user for TenantScope to act
        // on. The Module policy is what holds it, run against the learner the
        // signature names.
        Storage::fake('local');
        $s = $this->makeVideoLesson();
        $outsider = User::factory()->agent()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $this->get($this->pathOf($this->signedUrlFor($s['lesson'], $outsider)))
            ->assertForbidden();
    }

    public function test_a_locked_lesson_is_still_refused(): void
    {
        /*
         * ADR-031 §2.2 — "a locked lesson's content must not be streamable...
         * a client-side lock is decoration". The lock lives on the learner's
         * progress, so moving authorization into the URL is exactly where it
         * could have been lost.
         */
        Storage::fake('local');
        $s = $this->makeVideoLesson();

        // An UNPUBLISHED lesson, which LessonAccessGate checks first and
        // ahead of drip and sequence — the broadest of the three locks and
        // the one that needs no progress fixture to trigger.
        $s['lesson']->update(['is_published' => false]);

        $agent = User::factory()->agent()->create(['company_id' => $s['company']->id]);

        $this->get($this->pathOf($this->signedUrlFor($s['lesson'], $agent)))
            ->assertForbidden();
    }

    public function test_a_lesson_that_is_not_an_uploaded_file_is_a_404(): void
    {
        // An embedded YouTube video has a public content_ref and never goes
        // through here; asking anyway must not reveal that the row exists in
        // some other shape.
        Storage::fake('local');
        $s = $this->makeVideoLesson();
        $s['lesson']->update(['source_type' => 'embed', 'content_ref' => 'https://youtu.be/x']);
        $agent = User::factory()->agent()->create(['company_id' => $s['company']->id]);

        $this->get($this->pathOf($this->signedUrlFor($s['lesson'], $agent)))
            ->assertNotFound();
    }

    // ── What the resource hands the player ─────────────────────────────

    public function test_the_resource_signs_a_videos_inline_url_and_leaves_a_pdf_alone(): void
    {
        /*
         * Narrow on purpose. A PDF is fetched by JS, which sends the header
         * perfectly well and was fixed for this migration on 2026-09-04;
         * signing it too would be tidier to read and would widen the set of
         * forwardable links for no gain.
         */
        Storage::fake('local');
        $s = $this->makeVideoLesson();
        $agent = User::factory()->agent()->create(['company_id' => $s['company']->id]);

        $video = $this->actingAs($agent)
            ->getJson("/api/v1/module-lessons/{$s['lesson']->id}")
            ->assertOk()
            ->json('data.inline_url');

        $this->assertStringContainsString('inline-stream', $video);
        $this->assertStringContainsString('signature=', $video);
        $this->assertStringContainsString("u={$agent->id}", $video);

        $s['lesson']->update(['content_type' => 'pdf']);

        $pdf = $this->actingAs($agent)
            ->getJson("/api/v1/module-lessons/{$s['lesson']->id}")
            ->assertOk()
            ->json('data.inline_url');

        $this->assertStringNotContainsString('signature=', $pdf);
    }

    public function test_the_download_url_stays_authenticated(): void
    {
        // `stream_url` is the download button's URL and goes through
        // api.downloadAbsolute, which DOES send the header. It must keep its
        // auth:sanctum route — the signed one serves inline only.
        Storage::fake('local');
        $s = $this->makeVideoLesson();
        $agent = User::factory()->agent()->create(['company_id' => $s['company']->id]);

        $streamUrl = $this->actingAs($agent)
            ->getJson("/api/v1/module-lessons/{$s['lesson']->id}")
            ->assertOk()
            ->json('data.stream_url');

        $this->assertStringNotContainsString('signature=', $streamUrl);
        $this->assertStringContainsString("/module-lessons/{$s['lesson']->id}/stream", $streamUrl);
    }

    public function test_the_authenticated_stream_route_still_refuses_an_anonymous_caller(): void
    {
        /*
         * The companion to the test above, in its own method because
         * `actingAs` persists for the rest of a test and would quietly make
         * this assertion pass for the wrong reason.
         *
         * It matters: if the fix had been "open the stream route up", every
         * test in this file would still pass and the Academy would be public.
         */
        Storage::fake('local');
        $s = $this->makeVideoLesson();

        $this->getJson("/api/v1/module-lessons/{$s['lesson']->id}/stream")
            ->assertUnauthorized();
    }
}
