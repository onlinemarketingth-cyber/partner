<?php

namespace App\Http\Requests\Academy;

use App\Enums\ModuleContentType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TASK-146 / ADR-028 §2.3 — the learner reports RAW POSITIONS; the server
 * decides what they mean (ADR-028 §3 rejected trusting a client-reported
 * percentage). There is deliberately no `percent`, no `completed`, and no
 * `max_*` field here: a max is something the server computes, never
 * something a client asserts.
 *
 * `user_id` is likewise absent — ModuleLessonProgressService forces it to
 * the authenticated user, exactly as StoreModuleCompletionRequest does.
 */
class UpdateModuleLessonProgressRequest extends FormRequest
{
    /**
     * A learner may record progress on any lesson they are allowed to SEE.
     * ModulePolicy::view is the same check ModuleLessonController::stream()
     * makes, so "can open the file" and "can record having opened it" can
     * never diverge. Cross-tenant is already 404 before this runs: the
     * route-model-bound lesson is TenantScope'd (§5 rule 5).
     */
    public function authorize(): bool
    {
        /** @var \App\Models\ModuleLesson $moduleLesson */
        $moduleLesson = $this->route('moduleLesson');

        return $this->user()->can('view', $moduleLesson->module);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var \App\Models\ModuleLesson $moduleLesson */
        $moduleLesson = $this->route('moduleLesson');

        $isVideo = $moduleLesson->content_type === ModuleContentType::Video;
        $isPdf = $moduleLesson->content_type === ModuleContentType::Pdf;

        return [
            // Video. The upper bounds below are SANITY ceilings, not
            // business values (BR-7 does not apply): they stop a garbage
            // or overflowing integer reaching the database. The real
            // clamp — to this media's actual duration/page count — happens
            // in ModuleLessonProgressService, because only the server
            // knows what the media contains.
            'last_position_seconds' => [
                Rule::prohibitedIf(fn () => ! $isVideo),
                'sometimes', 'integer', 'min:0', 'max:86400',
            ],

            // PDF.
            'last_page' => [
                Rule::prohibitedIf(fn () => ! $isPdf),
                'sometimes', 'integer', 'min:1', 'max:10000',
            ],
            'total_pages' => [
                Rule::prohibitedIf(fn () => ! $isPdf),
                'sometimes', 'integer', 'min:1', 'max:10000',
            ],
        ];
    }

    /**
     * An empty PUT would create a bare progress row that says nothing —
     * noise in a table the Admin readout has to be trustworthy about
     * (ADR-028 §4). Reject it rather than silently accept it.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $reported = array_intersect_key(
                $this->all(),
                array_flip(['last_position_seconds', 'last_page', 'total_pages']),
            );

            if ($reported === []) {
                $validator->errors()->add('last_position_seconds', 'ต้องระบุความคืบหน้าอย่างน้อยหนึ่งค่า');
            }
        });
    }
}
