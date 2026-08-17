<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TASK-146 / ADR-028 §4 — the ADMIN readout.
 *
 * ADR-028 §4 (human decision, 2026-08-08) chose NOT to tell a blocked
 * learner how far they got, and then named the cost of that choice:
 * "expect support contacts from learners who believe they finished and
 * did not... Admin needs to *see* the recorded progress even though the
 * learner does not. Build the progress readout into the Admin lesson
 * view, not the learner's."
 *
 * This resource is that readout. It is returned ONLY from
 * GET /module-lessons/{lesson}/progress, which is gated on
 * ModulePolicy::update (Company Admin own company / Super Admin). The
 * learner's own PUT answers 204 No Content and carries none of this — no
 * max, no total, no percentage, in any field.
 */
class ModuleLessonProgressResource extends JsonResource
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
            // Both halves of the pair, deliberately: support needs to see
            // that a learner sits at last_page 3 while max_page is 12 to
            // understand a dispute (ADR-028 §2.3).
            'last_position_seconds' => $this->last_position_seconds,
            'max_position_seconds' => $this->max_position_seconds,
            'last_page' => $this->last_page,
            'max_page' => $this->max_page,
            'total_pages' => $this->total_pages,
            'updated_at' => $this->updated_at,
        ];
    }
}
