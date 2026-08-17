<?php

namespace App\Policies;

use App\Models\Exam;
use App\Models\User;

// Agent may view exam metadata (title, passing_score) to know what
// they're attempting — but ExamResource hides `config` (the
// question/answer content) from anyone but Company Admin/Super Admin,
// since it's effectively an answer key (ERD-001 open question #5).
class ExamPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Exam $exam): bool
    {
        return $user->isSuperAdmin() || $user->company_id === $exam->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, Exam $exam): bool
    {
        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $user->company_id === $exam->company_id);
    }

    public function delete(User $user, Exam $exam): bool
    {
        return $this->update($user, $exam);
    }
}
