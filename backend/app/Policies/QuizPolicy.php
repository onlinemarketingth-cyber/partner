<?php

namespace App\Policies;

use App\Models\Quiz;
use App\Models\User;

/**
 * TASK-150 / ADR-030 — authorization for the quiz library.
 *
 * Admin-only in every verb, including viewAny(). This is deliberately
 * NARROWER than ModulePolicy, whose viewAny/view are open to Agents because
 * an Agent must browse modules to learn (BR-1):
 *
 *   - The library listing carries the quizzes THEMSELVES, and an authoring
 *     view of a question includes `is_correct` (ModuleLessonQuizOptionResource
 *     has no masking of its own — the masking in ADR-029 §2.7 lives in
 *     ModuleLessonResource's learner-facing embed). Handing an Agent the
 *     library would hand them every answer key in the company.
 *   - An Agent has no use for it: what a learner needs is the quiz on the
 *     lesson in front of them, gated by the ADR-028 content gate, which
 *     ModuleLessonResource already serves.
 *
 * Same reasoning PipelineTemplatePolicy states for its own narrowness. If an
 * Agent-facing need ever appears, widen it here on purpose rather than by
 * accident.
 *
 * ADR-030 §4 item 1 is UNRESOLVED and is assumed the permissive way, exactly
 * as the ADR records: "Whether a Company Admin may see/attach quizzes
 * authored by another admin in the same company. **Assumed yes**
 * (company-scoped, BR-6); ask if it should be per-author." There is no
 * author column on `quizzes`, so per-author scoping would be a schema change
 * — flagged, not guessed.
 */
class QuizPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function view(User $user, Quiz $quiz): bool
    {
        return $user->isSuperAdmin()
            || ($user->isCompanyAdmin() && $user->company_id === $quiz->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, Quiz $quiz): bool
    {
        return $this->view($user, $quiz);
    }

    /**
     * Whether this ACTOR may delete quizzes at all. Whether THIS QUIZ may be
     * deleted right now is a different question — §2.4 forbids deleting a
     * linked one, and that is a business rule with a 422 and a message
     * telling the admin to unlink first, not an authorization failure with a
     * bare 403. It lives in QuizService::delete().
     */
    public function delete(User $user, Quiz $quiz): bool
    {
        return $this->view($user, $quiz);
    }
}
