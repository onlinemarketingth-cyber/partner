<?php

namespace App\Services\Academy;

use App\Models\ModuleLessonQuizOption;
use App\Models\ModuleLessonQuizQuestion;
use Illuminate\Support\Facades\DB;

// Mirrors ExamQuestionOptionService exactly — "at most one correct
// option per question" enforced by mutual exclusion in a transaction,
// not a DB constraint.
class ModuleLessonQuizOptionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(ModuleLessonQuizQuestion $question, array $data): ModuleLessonQuizOption
    {
        return DB::transaction(function () use ($question, $data) {
            $isCorrect = (bool) ($data['is_correct'] ?? false);

            if ($isCorrect) {
                $this->clearOtherCorrectOptions($question, null);
            }

            return ModuleLessonQuizOption::create([
                'company_id' => $question->company_id,
                'module_lesson_quiz_question_id' => $question->id,
                'option_text' => $data['option_text'],
                'is_correct' => $isCorrect,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ModuleLessonQuizOption $option, array $data): ModuleLessonQuizOption
    {
        return DB::transaction(function () use ($option, $data) {
            if (array_key_exists('is_correct', $data) && $data['is_correct']) {
                $this->clearOtherCorrectOptions($option->moduleLessonQuizQuestion, $option->id);
            }

            $option->update($data);

            return $option;
        });
    }

    public function delete(ModuleLessonQuizOption $option): void
    {
        $option->delete();
    }

    private function clearOtherCorrectOptions(ModuleLessonQuizQuestion $question, ?int $exceptOptionId): void
    {
        ModuleLessonQuizOption::where('module_lesson_quiz_question_id', $question->id)
            ->when($exceptOptionId, fn ($q) => $q->where('id', '!=', $exceptOptionId))
            ->update(['is_correct' => false]);
    }
}
