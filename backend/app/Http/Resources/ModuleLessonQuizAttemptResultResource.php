<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TASK-149 / ADR-029 §2.7 — the response to POST
 * /module-lessons/{lesson}/quiz-attempts.
 *
 * **"Which answers were wrong — never which answer is right."**
 *
 * Per question this returns exactly three things: the question's own id,
 * whether the learner answered it at all, and whether THEIR OWN answer was
 * correct. It does not return:
 *
 *   - the correct option's id            } either of these turns "you got it
 *   - the correct option's text          } wrong" into "here is the answer"
 *   - the learner's chosen option id — redundant (the client sent it) and
 *     omitting it means this payload contains NO option id at all, which is
 *     what makes the no-leak assertion in LessonQuizAttemptTest a flat
 *     "these ids and strings appear nowhere" rather than a per-field audit
 *     that has to be re-reasoned every time a field is added.
 *   - the pass percentage. It is a threshold, and ADR-028 §4 established
 *     that thresholds are not shown to learners (see
 *     AcademyCompletionSettingController, which withholds the same class of
 *     number). The learner is told PASSED or NOT, plus their own score.
 *
 * `is_correct` on options stays masked to null for the Agent role in
 * ModuleLessonResource, exactly as it is today (ADR-029 §2.7).
 *
 * Wraps the array ModuleLessonQuizAttemptService::attempt() returns.
 */
class ModuleLessonQuizAttemptResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\ModuleLessonQuizAttempt $attempt */
        $attempt = $this['attempt'];

        return [
            'id' => $attempt->id,
            'module_lesson_id' => $attempt->module_lesson_id,
            // Correct-answer COUNT out of the questions asked — the
            // learner's own result, not a threshold.
            'score' => $attempt->score,
            'total_questions' => $attempt->total_questions,
            'passed' => $attempt->passed,
            'attempted_at' => $attempt->attempted_at,
            // ADR-029 §2.7 — per-question feedback about the learner's OWN
            // answer. See the class docblock for everything absent here.
            'results' => $this['results'],
        ];
    }
}
