<?php

namespace App\Http\Requests\Academy\Concerns;

use App\Enums\MediaSourceType;
use App\Enums\ModuleContentType;
use App\Services\Catalog\VideoProcessingSettingService;
use Illuminate\Validation\Rule;

/**
 * TASK-188 §6.D1 — THE CONTENT SPEC OF A LESSON, VALIDATED IN ONE PLACE.
 *
 * These four rules (source_type / content_ref / file / is_downloadable) are
 * not independent: which of them is required, optional or prohibited is a
 * pure function of `content_type` plus `source_type`. That function used to
 * be written out inside StoreModuleLessonRequest, and Phase D needs the SAME
 * function on the update path — because changing a lesson's content type is
 * re-specifying its content, not editing a label.
 *
 * Extracted rather than copied. TASK-188's own §4 B3 says it about user-facing
 * copy and it is truer of predicates: a second copy is a copy that drifts, and
 * the two halves here decide whether a client-supplied path can be pointed at
 * another tenant's stored file (§5 rule 6) and whether an executable renamed
 * to .pdf reaches the disk. Those must not be able to disagree.
 *
 * Used by StoreModuleLessonRequest and UpdateModuleLessonRequest; both are
 * FormRequests, which is where `config()` and the `$this->input()` the callers
 * pass in come from.
 */
trait ValidatesLessonContent
{
    /**
     * ADR-028 §2.1 / ADR-007 — the content-shape rules for ONE (content_type,
     * source_type) pair. Every mime list and size ceiling comes from config
     * (BR-7); there are deliberately no inline literals.
     *
     * @param  ?ModuleContentType  $contentType  the type the lesson WILL have
     * @param  mixed  $sourceType  the RAW `source_type` input for that type —
     *                             deliberately untyped, because a client can
     *                             send `source_type[]=upload` and a string
     *                             type-hint would turn that into a 500 instead
     *                             of the 422 the enum rule below produces
     * @param  int  $companyId  the LESSON's company, never the actor's (BR-6)
     * @return array<string, mixed>
     */
    protected function lessonContentRules(
        ?ModuleContentType $contentType,
        mixed $sourceType,
        int $companyId,
        VideoProcessingSettingService $videoProcessingSettingService,
    ): array {
        $isVideo = $contentType === ModuleContentType::Video;
        $isQuiz = $contentType === ModuleContentType::Quiz;
        // ADR-028 §2.1 — pdf/image accept BOTH an external URL (no
        // source_type) and an upload, so source_type is optional for them
        // rather than required-or-prohibited as it is for video/quiz.
        $isFileType = $contentType?->isUploadableFile() ?? false;
        $isUploadSource = $sourceType === MediaSourceType::Upload->value;

        $isVideoUpload = $isVideo && $isUploadSource;
        $isFileUpload = $isFileType && $isUploadSource;
        $isUpload = $isVideoUpload || $isFileUpload;

        return [
            'source_type' => [
                Rule::requiredIf(fn () => $isVideo),
                Rule::prohibitedIf(fn () => ! $isVideo && ! $isFileType),
                Rule::enum(MediaSourceType::class),
            ],
            // A quiz lesson has no content_ref at all — its content is
            // authored separately via /module-lessons/{lesson}/quiz-questions.
            // An uploaded lesson has none either: the server owns the path
            // (§5 rule 6), so accepting one from the client would let a
            // caller point a lesson at any file on the private disk.
            'content_ref' => [
                Rule::requiredIf(fn () => ! $isQuiz && ! $isUpload),
                Rule::prohibitedIf(fn () => $isQuiz || $isUpload),
                'string', 'max:2048',
            ],
            'file' => [
                Rule::requiredIf(fn () => $isUpload),
                Rule::prohibitedIf(fn () => ! $isUpload),
                'file',
                // `mimes:` validates against the file's SNIFFED type, not
                // its name, which is what makes a .exe renamed to .pdf a
                // 422 rather than a stored executable (TASK-142 AC).
                'mimes:'.implode(',', $this->allowedLessonMimes($contentType, $isVideoUpload)),
                'max:'.$this->maxLessonUploadKilobytes($companyId, $isVideoUpload, $isFileUpload, $videoProcessingSettingService),
            ],
            /*
             * ADR-028 §2.2 — per-file admin choice, meaningful only for an
             * uploaded file (there is nothing of ours to download for an
             * external URL or an embed).
             *
             * TASK-188 §6.D2 — this is also the flag the audit calls out as
             * unable to combine with the "ดู/อ่านให้ครบ" rule: while it is on,
             * LessonCompletionGate::isMeasurable() returns false and the
             * lesson falls back to the button. Which is why it must be
             * re-stated on a type change rather than inherited — see
             * ModuleLessonService::changeContentType().
             */
            'is_downloadable' => [
                'sometimes', 'boolean',
                Rule::prohibitedIf(fn () => ! $isUpload),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allowedLessonMimes(?ModuleContentType $contentType, bool $isVideoUpload): array
    {
        if ($isVideoUpload) {
            return config('media.video.allowed_mimes');
        }

        return $contentType === ModuleContentType::Image
            ? config('media.image.allowed_mimes')
            : config('media.pdf.allowed_mimes');
    }

    private function maxLessonUploadKilobytes(
        int $companyId,
        bool $isVideoUpload,
        bool $isFileUpload,
        VideoProcessingSettingService $videoProcessingSettingService,
    ): int {
        if ($isVideoUpload) {
            // ADR-007/BR-7 — the COMPANY's configured video ceiling.
            return $videoProcessingSettingService->forCompany($companyId)['max_upload_mb'] * 1024;
        }

        if ($isFileUpload) {
            // ADR-028 §4 (human decision) — 20 MB platform-wide, reusing
            // the existing pdf ceiling. Explicitly NOT a per-company
            // academy setting.
            return (int) config('media.pdf.max_upload_mb') * 1024;
        }

        // Unreachable in practice (the `file` rules only evaluate when one
        // of the two above is true) — kept as a bounded fallback rather
        // than a null that would render as `max:` and validate nothing.
        return (int) config('media.pdf.max_upload_mb') * 1024;
    }
}
