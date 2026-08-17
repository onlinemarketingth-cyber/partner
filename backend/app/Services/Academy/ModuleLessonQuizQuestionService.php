<?php

namespace App\Services\Academy;

use App\Models\ModuleLessonQuizQuestion;
use App\Models\Quiz;

/**
 * Mirrors ExamQuestionService exactly.
 *
 * ADR-030 §2.1 (TASK-150) — a question is created against a **Quiz**, not a
 * lesson. The lesson-scoped authoring route still works exactly as before:
 * ModuleLessonQuizQuestionController::store() calls
 * QuizService::ensureForLesson() first, which creates-and-attaches a quiz on
 * the spot (§3 — "create a new quiz right here" stays the default path).
 */
class ModuleLessonQuizQuestionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Quiz $quiz, array $data): ModuleLessonQuizQuestion
    {
        return ModuleLessonQuizQuestion::create([
            // From the QUIZ, never from the actor: a Super Admin authoring
            // inside another company must not stamp their own company_id
            // (BR-6/§5 rule 1).
            'company_id' => $quiz->company_id,
            'quiz_id' => $quiz->id,
            'question_text' => $data['question_text'],
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ModuleLessonQuizQuestion $question, array $data): ModuleLessonQuizQuestion
    {
        $question->update($data);

        return $question;
    }

    public function delete(ModuleLessonQuizQuestion $question): void
    {
        $question->delete();
    }
}
