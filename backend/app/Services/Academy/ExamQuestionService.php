<?php

namespace App\Services\Academy;

use App\Models\Exam;
use App\Models\ExamQuestion;

class ExamQuestionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Exam $exam, array $data): ExamQuestion
    {
        return ExamQuestion::create([
            'company_id' => $exam->company_id,
            'exam_id' => $exam->id,
            'question_text' => $data['question_text'],
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ExamQuestion $examQuestion, array $data): ExamQuestion
    {
        $examQuestion->update($data);

        return $examQuestion;
    }

    public function delete(ExamQuestion $examQuestion): void
    {
        $examQuestion->delete();
    }
}
