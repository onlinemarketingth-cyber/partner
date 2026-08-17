<?php

namespace App\Policies;

use App\Models\Module;
use App\Models\User;

// Mirrors ProductPolicy's shape — Agent needs to browse modules to
// learn (BR-1), only Company Admin/Super Admin author content.
class ModulePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Module $module): bool
    {
        return $user->isSuperAdmin() || $user->company_id === $module->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, Module $module): bool
    {
        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $user->company_id === $module->company_id);
    }

    public function delete(User $user, Module $module): bool
    {
        return $this->update($user, $module);
    }

    /**
     * TASK-152 — read the cross-agent Academy progress dashboard.
     *
     * A class-level ability (there is no single Module being asked about), and
     * deliberately NOT `viewAny`: that one returns true for everyone because an
     * Agent must be able to browse the syllabus in order to learn from it
     * (BR-1). This ability answers a different question — "may this user read
     * how far OTHER PEOPLE have got" — and the answer for an Agent is no.
     *
     * Same reasoning, and the same audience, as the ADR-028 §4 lesson-progress
     * readout and the ADR-029 §2.5 quiz-attempt readout, both of which gate on
     * ModulePolicy::update for exactly this reason. This is a separate method
     * only because there is no Module instance to hand it.
     *
     * Company scoping is NOT decided here — a Company Admin passes this check
     * for their own company only because AcademyProgressSummaryRequest refuses
     * any other `company_id` (BR-6).
     */
    public function viewProgressSummary(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }
}
