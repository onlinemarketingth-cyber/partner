<?php

namespace App\Http\Requests\Academy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TASK-146 / ADR-028 §2.3 guard 2 — a Company Admin (own company) or
 * Super Admin marks a lesson complete FOR an agent who could not satisfy
 * the gate (a file that would not render, a broken device, a printout).
 *
 * Authorization reuses ModulePolicy::update — the same "who may author
 * this course" test — exactly as ModuleLessonController does, rather than
 * introducing a parallel Policy that could drift from it.
 *
 * The lesson comes from the route (TenantScope'd, so another company's id
 * is a 404 before this class runs — §5 rule 5); the target user is scoped
 * to that lesson's company here, so a Super Admin cannot accidentally
 * complete a Thai Life lesson on behalf of another tenant's agent.
 */
class StoreModuleCompletionOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var \App\Models\ModuleLesson $moduleLesson */
        $moduleLesson = $this->route('moduleLesson');

        return $this->user()->can('update', $moduleLesson->module);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var \App\Models\ModuleLesson $moduleLesson */
        $moduleLesson = $this->route('moduleLesson');

        return [
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where('company_id', $moduleLesson->company_id),
            ],
        ];
    }
}
