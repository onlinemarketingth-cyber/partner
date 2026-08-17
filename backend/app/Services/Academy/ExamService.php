<?php

namespace App\Services\Academy;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ExamService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Exam
    {
        $companyId = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;

        if ($companyId === null) {
            throw ValidationException::withMessages(['company_id' => 'company_id is required.']);
        }

        $data['company_id'] = $companyId;

        return Exam::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Exam $exam, array $data): Exam
    {
        $exam->update($data);

        return $exam;
    }
}
