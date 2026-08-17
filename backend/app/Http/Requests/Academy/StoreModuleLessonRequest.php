<?php

namespace App\Http\Requests\Academy;

use App\Enums\ModuleContentType;
use App\Http\Requests\Academy\Concerns\ValidatesLessonContent;
use App\Models\Module;
use App\Services\Catalog\VideoProcessingSettingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-009 — carries the exact ADR-007 video upload/embed validation
// logic that used to live in StoreModuleRequest, now scoped to a
// Lesson nested under a Section (module_id comes from the route, not
// the body — see routes/api.php's `/modules/{module}/lessons`).
//
// ADR-028 §2.1 (TASK-142) — `file` is no longer video-only. content_type
// pdf and the new image may also carry source_type=upload. Every mime
// list and size ceiling below comes from config (BR-7); there are
// deliberately no inline literals.
class StoreModuleLessonRequest extends FormRequest
{
    // TASK-188 §6.D1 — the content-shape rules below used to be written out
    // in this class. They now live in one place so the UPDATE path can apply
    // the SAME ones when a lesson's content_type changes.
    use ValidatesLessonContent;

    public function __construct(private readonly VideoProcessingSettingService $videoProcessingSettingService)
    {
        parent::__construct();
    }

    public function authorize(): bool
    {
        /** @var Module $module */
        $module = $this->route('module');

        return $this->user()->can('update', $module);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Module $module */
        $module = $this->route('module');
        $companyId = $module->company_id;

        $contentType = ModuleContentType::tryFrom((string) $this->input('content_type'));

        return [
            'title' => ['required', 'string', 'max:255'],
            'content_type' => ['required', Rule::enum(ModuleContentType::class)],
            ...$this->lessonContentRules(
                $contentType,
                $this->input('source_type'),
                $companyId,
                $this->videoProcessingSettingService,
            ),
            /*
             * ADR-029 §2.4 — NULL means "inherit the company setting", so
             * `nullable` is a meaningful value here rather than sloppiness.
             *
             * Deliberately NOT prohibited on a non-quiz content type: §2.1
             * makes any lesson able to carry a quiz, and the questions are
             * authored AFTER the lesson exists
             * (POST /module-lessons/{lesson}/quiz-questions), so "does this
             * lesson have a quiz yet" is never knowable at create time.
             *
             * 1..100 are sanity bounds around an admin-editable value, not
             * business values in themselves (BR-7's target is the DEFAULT,
             * which lives in config/academy.php and the table default). The
             * lower bound is 1 for the same reason as ADR-028's thresholds:
             * a 0% pass mark would mean "answering nothing passes", which
             * silently disables the gate with no audit trail.
             */
            'quiz_pass_percent' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            // ADR-029 §2.6 — per-lesson, because the same course mixes "you
            // must know this" with "here is some background".
            'quiz_blocks_completion' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'xp_reward' => ['sometimes', 'integer', 'min:0'],
            'is_published' => ['sometimes', 'boolean'],
            /*
             * ADR-031 §2.4 — "shown, not counted". Deliberately not
             * prohibited on any content type: supplementary reading is just
             * as likely to be a video as a PDF, and the flag says nothing
             * about the medium.
             *
             * It is also NOT mutually exclusive with quiz_blocks_completion:
             * an optional lesson that carries a blocking quiz is coherent —
             * you need not do it, but if you do, do it properly.
             */
            'is_optional' => ['sometimes', 'boolean'],
        ];
    }
}
