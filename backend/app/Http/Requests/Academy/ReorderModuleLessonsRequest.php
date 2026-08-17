<?php

namespace App\Http\Requests\Academy;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-151 / ADR-031 §2.1 — the FULL ordered list of lesson ids within one
 * Section.
 *
 * The parent Section arrives through route-model binding, so it is already
 * TenantScope'd to 404 for another company (§5 rule 5) before this runs —
 * which is why authorize() can ask the Policy about it directly, exactly as
 * StoreModuleLessonRequest does.
 *
 * "Do these lesson ids actually belong to THIS Section" is checked in
 * ModuleOrderService: the lesson routes are flat, so a same-company lesson
 * from a different Section is visible to this actor and would otherwise be
 * renumbered into a list it is not part of.
 */
class ReorderModuleLessonsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var \App\Models\Module $module */
        $module = $this->route('module');

        return $this->user()->can('update', $module);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lesson_ids' => ['required', 'array', 'min:1'],
            'lesson_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
