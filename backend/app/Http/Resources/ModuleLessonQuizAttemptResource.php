<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TASK-149 / ADR-029 §2.5 — the ADMIN readout of a lesson's quiz attempts.
 *
 * "Every attempt is still recorded, so the admin can see someone who took
 * eleven tries." That is what this listing is for.
 *
 * **Score only, never the chosen answers.** ADR-029 §4 item 2 — "whether an
 * admin should be able to see an individual learner's chosen answers, or
 * only the score" — is UNRESOLVED and PDPA-adjacent, and the ADR's own
 * instruction is "score only until asked". The answers are not merely
 * omitted from this Resource, they are not stored at all (see the
 * migration), so this cannot be widened by accident.
 *
 * Deliberately no `results` array either: that is per-question feedback for
 * the person who answered, not oversight data.
 */
class ModuleLessonQuizAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'first_name' => $this->user->first_name,
                'last_name' => $this->user->last_name,
            ]),
            'module_lesson_id' => $this->module_lesson_id,
            'score' => $this->score,
            'total_questions' => $this->total_questions,
            'passed' => $this->passed,
            'attempted_at' => $this->attempted_at,
        ];
    }
}
