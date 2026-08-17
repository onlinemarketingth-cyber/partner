<?php

namespace App\Policies;

use App\Models\ExamAttempt;
use App\Models\User;

// Mirrors ModuleCompletionPolicy — append-only, Agent creates only their
// own attempts, Company Admin/Super Admin can view all in company.
class ExamAttemptPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ExamAttempt $examAttempt): bool
    {
        return $user->isSuperAdmin()
            || $user->id === $examAttempt->user_id
            || ($user->isCompanyAdmin() && $user->company_id === $examAttempt->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isAgent();
    }
}
