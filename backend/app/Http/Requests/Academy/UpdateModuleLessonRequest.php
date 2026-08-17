<?php

namespace App\Http\Requests\Academy;

use App\Enums\MediaSourceType;
use App\Enums\ModuleContentType;
use App\Http\Requests\Academy\Concerns\ValidatesLessonContent;
use App\Models\ModuleLesson;
use App\Services\Catalog\VideoProcessingSettingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-009 — carries forward the same "no free-form content-type swap"
// rule the old UpdateModuleRequest had (see this project's Sprint-4
// note): source_type still can't be changed here, and content_ref is
// still prohibited for an uploaded video (server-controlled path).
// What CAN be replaced in place: the uploaded video FILE itself, via
// `file` — same as before, just moved down to Lesson scope.
//
// ADR-028 §2.1 (TASK-142) — the same in-place replace now applies to an
// uploaded PDF or image, with that type's own mime allow-list and the
// platform-wide 20 MB ceiling (ADR-028 §4). Mimes and sizes come from
// config, never inline (BR-7).
//
// TASK-188 §6.D (human decision D2, 2026-08-13) — the "no content-type swap"
// rule above is LIFTED. Choosing the wrong type used to mean deleting the
// lesson and rebuilding it, "which takes every learner's progress on it with
// it" (§1). It is lifted in ONE specific shape, and the shape is the point:
//
//   a content_type change is a RE-SPECIFICATION of the lesson's content,
//   validated by exactly the rules the CREATE path applies to that type.
//
// So a swap to an upload type demands a new file, a swap to an external type
// demands a new content_ref, a swap to quiz permits neither, and
// is_downloadable is re-stated rather than inherited. There is no reachable
// payload that leaves a video lesson pointing at the old PDF, because that
// half-state is not representable — see ValidatesLessonContent, which is the
// one copy of those rules (§6.D1: "reuse that validation, do not write a
// second copy").
class UpdateModuleLessonRequest extends FormRequest
{
    use ValidatesLessonContent;

    public function __construct(private readonly VideoProcessingSettingService $videoProcessingSettingService)
    {
        parent::__construct();
    }

    public function authorize(): bool
    {
        /** @var ModuleLesson $moduleLesson */
        $moduleLesson = $this->route('moduleLesson');

        return $this->user()->can('update', $moduleLesson->module);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var ModuleLesson $moduleLesson */
        $moduleLesson = $this->route('moduleLesson');

        $contentRules = $this->isContentTypeChanging()
            ? $this->lessonContentRules(
                ModuleContentType::tryFrom((string) $this->input('content_type')),
                $this->input('source_type'),
                $moduleLesson->company_id,
                $this->videoProcessingSettingService,
            )
            : $this->unchangedContentTypeRules($moduleLesson);

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content_type' => ['sometimes', 'required', Rule::enum(ModuleContentType::class)],
            ...$contentRules,
            // ADR-029 §2.4 / §2.6 — same rules as StoreModuleLessonRequest;
            // see that class for why null is meaningful (inherit) and why
            // neither field is restricted to a quiz content type.
            'quiz_pass_percent' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'quiz_blocks_completion' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'xp_reward' => ['sometimes', 'integer', 'min:0'],
            'is_published' => ['sometimes', 'boolean'],
            // ADR-031 §2.4 — same rule as StoreModuleLessonRequest. Flipping
            // it on an existing lesson moves the Section's denominator for
            // every learner, which is the point: "3/5" becomes "3/4", not
            // "3/5 forever".
            'is_optional' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * TASK-188 §6.D1 — is this request actually RETYPING the lesson?
     *
     * A payload that repeats the lesson's CURRENT content_type is not a
     * retype and must keep behaving exactly as it did before this task. The
     * admin edit form sends the lesson back on every save, and making "rename
     * the lesson" suddenly demand a re-upload would be a worse trap than the
     * one §1 is fixing.
     *
     * Public because ModuleLessonService asks the same question before it
     * writes. It does so from the MODEL, not from this method — two doors,
     * one rule, same reasoning as re-checking a Policy in a Service.
     */
    public function isContentTypeChanging(): bool
    {
        /** @var ModuleLesson $moduleLesson */
        $moduleLesson = $this->route('moduleLesson');

        if (! $this->has('content_type')) {
            return false;
        }

        $incoming = ModuleContentType::tryFrom((string) $this->input('content_type'));

        // tryFrom() is null for a bogus value; that is the enum rule's 422 to
        // report, not a retype whose content rules we should start deriving.
        return $incoming !== null && $incoming !== $moduleLesson->content_type;
    }

    /**
     * The pre-TASK-188 rules, byte-for-byte: the lesson KEEPS its type, so
     * the shape of its content is decided by what it already is.
     *
     * `source_type` is deliberately still absent — with no content_type
     * change there is no re-specification to hang it on, and flipping
     * upload<->embed on its own would strand content_ref. Absent (not
     * `prohibited`) so an admin form that echoes the field back keeps
     * getting it silently dropped from validated(), exactly as before.
     *
     * @return array<string, mixed>
     */
    private function unchangedContentTypeRules(ModuleLesson $moduleLesson): array
    {
        $isUploadSource = $moduleLesson->source_type === MediaSourceType::Upload;
        $isUploadedVideo = $isUploadSource && $moduleLesson->content_type === ModuleContentType::Video;
        $isUploadedFile = $isUploadSource && ($moduleLesson->content_type?->isUploadableFile() ?? false);
        $isUpload = $isUploadedVideo || $isUploadedFile;
        $isQuiz = $moduleLesson->content_type === ModuleContentType::Quiz;

        return [
            'content_ref' => [
                'sometimes', 'required', 'string', 'max:2048',
                // Server-controlled for ANY upload now, not just video
                // (§5 rule 6 — accepting a path here would let a caller
                // repoint a lesson at another tenant's stored file).
                Rule::prohibitedIf(fn () => $isUpload || $isQuiz),
            ],
            'file' => [
                'sometimes',
                'file',
                'mimes:'.implode(',', $this->allowedLessonMimes($moduleLesson->content_type, $isUploadedVideo)),
                'max:'.$this->maxLessonUploadKilobytes(
                    $moduleLesson->company_id,
                    $isUploadedVideo,
                    $isUploadedFile,
                    $this->videoProcessingSettingService,
                ),
                Rule::prohibitedIf(fn () => ! $isUpload),
            ],
            // ADR-028 §2.2 — flipping this on an existing lesson is the
            // normal way an admin decides a document may be kept.
            'is_downloadable' => [
                'sometimes', 'boolean',
                Rule::prohibitedIf(fn () => ! $isUpload),
            ],
        ];
    }
}
