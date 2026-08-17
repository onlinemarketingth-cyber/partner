<?php

namespace App\Services\Academy;

use App\Models\ExamQuestion;
use App\Models\ExamQuestionOption;
use Illuminate\Support\Facades\DB;

// Academy Sprint 1 — enforces "at most one correct option per question"
// by mutual exclusion (radio-button semantics) rather than a DB
// constraint, since the invariant spans multiple sibling rows: whenever
// an option is saved with is_correct=true, every other option under the
// same question is atomically flipped to false first. This means the
// invariant holds at all times without needing a "submit the whole
// option set at once" API shape — options can still be authored one at a
// time, matching this codebase's usual granular-CRUD convention (e.g.
// product_specs).
class ExamQuestionOptionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(ExamQuestion $examQuestion, array $data): ExamQuestionOption
    {
        return DB::transaction(function () use ($examQuestion, $data) {
            $isCorrect = (bool) ($data['is_correct'] ?? false);

            if ($isCorrect) {
                $this->clearOtherCorrectOptions($examQuestion, null);
            }

            return ExamQuestionOption::create([
                'company_id' => $examQuestion->company_id,
                'exam_question_id' => $examQuestion->id,
                'option_text' => $data['option_text'],
                'is_correct' => $isCorrect,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ExamQuestionOption $option, array $data): ExamQuestionOption
    {
        return DB::transaction(function () use ($option, $data) {
            if (array_key_exists('is_correct', $data) && $data['is_correct']) {
                $this->clearOtherCorrectOptions($option->examQuestion, $option->id);
            }

            $option->update($data);

            return $option;
        });
    }

    public function delete(ExamQuestionOption $option): void
    {
        $option->delete();
    }

    private function clearOtherCorrectOptions(ExamQuestion $examQuestion, ?int $exceptOptionId): void
    {
        ExamQuestionOption::where('exam_question_id', $examQuestion->id)
            ->when($exceptOptionId, fn ($q) => $q->where('id', '!=', $exceptOptionId))
            ->update(['is_correct' => false]);
    }
}
