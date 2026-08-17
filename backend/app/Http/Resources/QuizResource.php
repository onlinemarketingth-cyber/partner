<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TASK-150 / ADR-030 — a quiz in the library.
 *
 * Admin-facing only (QuizPolicy is admin-only in every verb), so unlike
 * ModuleLessonResource there is no learner-facing masking to do here. The
 * questions are NOT embedded: the library list is a picker, and shipping
 * every question of every quiz to render a dropdown is a payload nobody
 * asked for. `GET /quizzes/{quiz}` loads them when the admin actually opens
 * one.
 */
class QuizResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'title' => $this->title,
            // ADR-030 §3 — "the library will accumulate orphans (authored,
            // never attached). SHOW THEM AS SUCH; do not auto-delete
            // anything an admin typed." These two fields are how the UI can.
            'question_count' => $this->whenCounted('questions'),
            'is_attached' => $this->moduleLesson !== null,
            /*
             * Which lesson holds it — the answer to "why can't I attach
             * this?" before the admin has to ask. Loaded relation only, so a
             * caller that did not eager-load it does not silently trigger a
             * query per row.
             */
            'module_lesson' => $this->whenLoaded('moduleLesson', fn () => $this->moduleLesson ? [
                'id' => $this->moduleLesson->id,
                'title' => $this->moduleLesson->title,
                'module_id' => $this->moduleLesson->module_id,
            ] : null),
            'questions' => ModuleLessonQuizQuestionResource::collection($this->whenLoaded('questions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
