<?php

namespace App\Services\Academy;

use App\Models\ModuleLesson;
use App\Models\ModuleLessonProgress;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * TASK-146 / ADR-028 §2.3 — the ONLY writer of module_lesson_progress.
 *
 * Two rules live here and nowhere else, because splitting them across
 * callers is how this feature is usually broken:
 *
 * 1. **`max_*` is monotonic.** It is written with max(), never assignment.
 *    A learner who scrubs a video back to 0:00, or pages back to page 1,
 *    keeps every second and every page they already reached. `last_*` is
 *    the only thing that follows them backwards, and `last_*` is never
 *    read by the gate (ADR-028 §2.3).
 *
 * 2. **The client is not trusted.** ADR-028 §3 rejected "trusting a
 *    client-reported completion percentage": the client reports raw
 *    positions, the SERVER decides what they mean. Every incoming number
 *    is clamped to what the media can actually contain before it is
 *    stored, so a forged `last_position_seconds: 9999` on a 60-second
 *    video records 60, not 9999.
 *
 * On `total_pages`, stated honestly: it is client-reported, and it is only
 * a FALLBACK. For an uploaded PDF the real denominator is
 * `module_lessons.page_count`, which ModuleLessonService measures with
 * `pdfinfo` at upload time — a number the client never touches. The
 * client's own report is used only when that measurement is unavailable
 * (an external-URL PDF, or a host without poppler), and even then it is
 * monotonic NON-DECREASING so a client cannot shrink its own denominator
 * to reach the threshold early. Over-reporting only makes the gate harder
 * for the reporter, so it needs no guard.
 */
class ModuleLessonProgressService
{
    public function __construct(
        private LessonAccessGate $access,
        // TASK-165 §3.2 — the automatic completion trigger. See the tail of
        // record().
        private ModuleCompletionService $completions,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated payload — see UpdateModuleLessonProgressRequest
     * @return array{progress: ModuleLessonProgress, completed: bool}
     *
     * Returns the recorded row AND whether the lesson is now complete.
     * The pair rather than the row alone because TASK-165 §3.4 needs the
     * second half in the response and CLAUDE.md §7 keeps the controller
     * thin — deciding it there would be business logic in a Controller.
     */
    public function record(ModuleLesson $lesson, User $user, array $data): array
    {
        /*
         * TASK-151 / ADR-031 §2.2 — a LOCKED lesson records nothing.
         *
         * Third of the four enforcement points (see LessonAccessGate's
         * docblock). Without it the stream refusal is cosmetic: a caller
         * who skips the player entirely and PUTs positions straight at this
         * endpoint would bank the `max_*` that satisfies ADR-028's content
         * gate, and then walk through the completion POST the moment the
         * lock lifted — having watched nothing.
         *
         * 422 rather than 403, matching the completion refusal, so the two
         * write endpoints on this path answer in one envelope shape. Only
         * stream() answers 403, because it serves bytes and has no
         * validation envelope to put a message in.
         *
         * `module_lesson_id` is the key even though it is not an input
         * field: it names the SUBJECT of the refusal, and it is the same
         * key ModuleCompletionService uses, so a client has one place to
         * look for "you may not touch this lesson yet".
         */
        $lockReason = $this->access->reasonFor($lesson, $user);

        if ($lockReason !== null) {
            throw ValidationException::withMessages([
                'module_lesson_id' => $lockReason->message(),
            ]);
        }

        $progress = ModuleLessonProgress::firstOrNew([
            'user_id' => $user->id,
            'module_lesson_id' => $lesson->id,
        ]);

        // §5/BR-6 — company_id is resolved from the LESSON on the server.
        // It is never accepted from the client, and never taken from the
        // user either: the two are already proven equal by the route's
        // TenantScope, and reading it from the record being written keeps
        // the row internally consistent.
        $progress->company_id = $lesson->company_id;

        // total_pages first: it is the clamp bound for last_page below.
        if (($data['total_pages'] ?? null) !== null) {
            $progress->total_pages = max((int) $progress->total_pages, (int) $data['total_pages']);
        }

        if (($data['last_position_seconds'] ?? null) !== null) {
            $position = max(0, (int) $data['last_position_seconds']);

            // Clamp to the server-known duration. When duration_seconds is
            // null (an embed, or ffprobe unavailable) there is nothing
            // truthful to clamp against — and LessonCompletionGate treats
            // that same null as "not verifiable", so an unclamped value
            // cannot buy the learner a completion either way.
            if ($lesson->duration_seconds !== null) {
                $position = min($position, (int) $lesson->duration_seconds);
            }

            $progress->last_position_seconds = $position;
            $progress->max_position_seconds = max((int) $progress->max_position_seconds, $position);
        }

        if (($data['last_page'] ?? null) !== null) {
            $page = max(1, (int) $data['last_page']);

            // Clamp to the SERVER-measured page count when we have one
            // (pdfinfo at upload time), falling back to the client's own
            // monotonic report otherwise. Same precedence the gate uses,
            // so a clamped page can never exceed the denominator it will
            // later be compared against.
            $ceiling = $lesson->page_count ?: $progress->total_pages;

            if ($ceiling) {
                $page = min($page, (int) $ceiling);
            }

            $progress->last_page = $page;
            $progress->max_page = max((int) $progress->max_page, $page);
        }

        $progress->save();

        /*
         * TASK-165 §3.2 — COMPLETION IS RECORDED, NOT DECLARED.
         *
         * ADR-028 §1 already said "completion is EARNED, not asserted", but
         * the learner still had to assert it: they read a PDF to the last
         * page and then pressed a button labelled "ทำเครื่องหมายว่าเรียนจบ"
         * — mark it as finished — which is the language of asserting. Where
         * the server can measure, it now records on its own and the button
         * is gone (ModuleLessonResource::completion_is_automatic).
         *
         * AFTER $progress->save(), never before: the gate reads the row we
         * have just written, and asking it first would judge the previous
         * ping.
         *
         * completeIfEarned() cannot throw — a learner 40% through a video
         * gets `false`, which is the normal answer several times a minute,
         * not an error to interrupt them with.
         */
        $completed = $this->completions->completeIfEarned($lesson, $user);

        return ['progress' => $progress, 'completed' => $completed];
    }
}
