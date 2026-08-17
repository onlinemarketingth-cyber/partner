<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserCertification;

// Read-only from the API's perspective — rows are only ever created as
// a side effect of ExamAttemptService when an attempt passes (BR-1),
// never directly via a Store endpoint. No create() here on purpose.
class UserCertificationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserCertification $userCertification): bool
    {
        return $user->isSuperAdmin()
            || $user->id === $userCertification->user_id
            || ($user->isCompanyAdmin() && $user->company_id === $userCertification->company_id);
    }
}
