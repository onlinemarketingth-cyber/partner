<?php

namespace App\Services\Academy;

use App\Enums\MediaProcessingStatus;
use App\Enums\MediaSourceType;
use App\Enums\ModuleContentType;
use App\Jobs\CompressUploadedVideo;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\ModuleCompletion;
use App\Models\ModuleLesson;
use App\Models\ModuleLessonProgress;
use App\Models\User;
use App\Support\Media\PdfPageCounter;
use App\Support\Media\StoredFileName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// ADR-009 — carries forward everything ModuleService used to do for a
// content item (video upload/compress, in-place file replace on
// update) — see that class's pre-2026-07-22 history — now scoped one
// level down, one Lesson at a time within a Section.
//
// ADR-028 §2.1 (TASK-142) — a lesson may now also hold an uploaded PDF or
// image. Those land at academy-lessons/{company_id}/{lesson_id}/{uuid}.{ext},
// which adds the lesson_id segment the legacy video path
// (academy-modules/{company_id}/) lacks. The video path is deliberately
// NOT migrated to match: every existing video lesson's content_ref points
// into the old layout, and rewriting them for tidiness would risk
// orphaning working course material for no user-visible gain.
class ModuleLessonService
{
    private const DISK = 'local';

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Module $module, array $data, ?UploadedFile $file = null): ModuleLesson
    {
        $data['company_id'] = $module->company_id;
        $data['module_id'] = $module->id;

        $isUpload = ($data['source_type'] ?? null) === MediaSourceType::Upload->value;
        $isUploadedVideo = $isUpload && $data['content_type'] === ModuleContentType::Video->value;
        $isUploadedFile = $isUpload && in_array(
            ModuleContentType::tryFrom((string) $data['content_type']),
            ModuleContentType::uploadableFiles(),
            true,
        );

        if ($isUploadedVideo && $file) {
            $data['content_ref'] = $file->storeAs(
                "academy-modules/{$module->company_id}",
                StoredFileName::random($file),
                self::DISK,
            );
            $data['processing_status'] = MediaProcessingStatus::Pending->value;
        }

        $lesson = ModuleLesson::create($data);

        // ADR-028 §2.1 — the pdf/image path contains the lesson id, so the
        // file can only be stored once the row exists. A failure between
        // the two writes leaves a lesson with a null content_ref (visibly
        // broken in the Admin UI and fixable by re-uploading), which is
        // strictly better than a stored file with no row pointing at it.
        if ($isUploadedFile && $file) {
            $path = $this->storeLessonFile($lesson, $file);

            $lesson->update([
                'content_ref' => $path,
                'page_count' => $this->pageCountFor($lesson, $path),
            ]);
        }

        if ($isUploadedVideo) {
            CompressUploadedVideo::dispatch($lesson->id, ModuleLesson::class, self::DISK);
        }

        return $lesson;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ModuleLesson $lesson, array $data, ?UploadedFile $file = null, ?User $actor = null): ModuleLesson
    {
        /*
         * TASK-188 §6.D — a content_type change is not an edit of one column,
         * it is a re-specification of the lesson's content, and it has to go
         * down its own path.
         *
         * Asked of the MODEL, not of the request: the Form Request asks the
         * same question to pick its rules, and this asks it again to pick its
         * write path. Two doors, one rule — the same defence-in-depth shape
         * CLAUDE.md §4.3 requires of the pipeline template check ("enforced in
         * the Form Request *and* re-checked in the Service").
         */
        $newType = isset($data['content_type'])
            ? ModuleContentType::tryFrom((string) $data['content_type'])
            : null;

        if ($newType !== null && $newType !== $lesson->content_type) {
            return $this->changeContentType($lesson, $newType, $data, $file, $actor);
        }

        $isUploadedVideo = $lesson->content_type === ModuleContentType::Video && $lesson->source_type === MediaSourceType::Upload;
        $isUploadedFile = $lesson->source_type === MediaSourceType::Upload
            && ($lesson->content_type?->isUploadableFile() ?? false);

        if ($isUploadedVideo && $file) {
            $oldPath = $lesson->content_ref;

            $data['content_ref'] = $file->storeAs(
                "academy-modules/{$lesson->company_id}",
                StoredFileName::random($file),
                self::DISK,
            );
            $data['processing_status'] = MediaProcessingStatus::Pending->value;

            $lesson->update($data);

            // Best-effort cleanup of the file being replaced — see
            // ModuleService's pre-split history for why this isn't
            // wrapped in a transaction with the DB update above.
            if ($oldPath) {
                Storage::disk(self::DISK)->delete($oldPath);
            }

            CompressUploadedVideo::dispatch($lesson->id, ModuleLesson::class, self::DISK);

            return $lesson;
        }

        // ADR-028 §2.1 — same in-place replace for an uploaded pdf/image.
        // No compression job: neither type goes anywhere near ffmpeg.
        if ($isUploadedFile && $file) {
            $oldPath = $lesson->content_ref;

            $data['content_ref'] = $this->storeLessonFile($lesson, $file);
            // Re-measured, not carried over: replacing the file with a
            // longer or shorter document must move the gate's denominator
            // with it (ADR-028 §2.3).
            $data['page_count'] = $this->pageCountFor($lesson, $data['content_ref']);

            $lesson->update($data);

            if ($oldPath) {
                Storage::disk(self::DISK)->delete($oldPath);
            }

            return $lesson;
        }

        $lesson->update($data);

        return $lesson;
    }

    /**
     * TASK-188 §6.D2 — WHAT A CONTENT-TYPE CHANGE ACTUALLY DOES, stated as
     * data so the Admin can be told before they press it rather than after.
     *
     * Read-only. Computed for the lesson AS IT IS NOW, so it describes the
     * change that is about to happen, whatever the new type turns out to be —
     * every consequence below is a property of what is being LEFT BEHIND, not
     * of what is being moved to.
     *
     * @return array<string, mixed>
     */
    public function contentTypeChangeImpact(ModuleLesson $lesson): array
    {
        /*
         * withoutGlobalScopes + an explicit, server-resolved lesson id, for
         * the same reason LessonCompletionGate gives: this runs for an ADMIN,
         * and for a Super Admin TenantScope does not filter at all while for a
         * Company Admin it would filter these rows by the ADMIN's company
         * rather than the lesson's. The lesson was already authorized by
         * ModulePolicy::update, and every progress/completion row for a lesson
         * carries that lesson's own company_id, so scoping by the lesson id
         * narrows rather than widens (BR-6).
         */
        $learnersWithProgress = ModuleLessonProgress::withoutGlobalScopes()
            ->where('module_lesson_id', $lesson->id)
            ->distinct()
            ->count('user_id');

        $learnersCompleted = ModuleCompletion::withoutGlobalScopes()
            ->where('module_lesson_id', $lesson->id)
            ->distinct()
            ->count('user_id');

        return [
            'content_type' => $lesson->content_type?->value,
            // §6.D3(a) — "naming the number of affected learners".
            'learners_with_progress' => $learnersWithProgress,
            'progress_will_be_reset' => $learnersWithProgress > 0,
            /*
             * ADR-028 §2.3 guard 1 / ADR-029 §3 — GRANDFATHERING, unchanged.
             * A completion already earned is never re-evaluated against a
             * newer rule, and a content-type change is a newer rule. These
             * learners keep the lesson, and therefore keep whatever BR-1
             * certification stands on it.
             */
            'learners_completed' => $learnersCompleted,
            'completions_are_kept' => true,
            // The old file is unreferenced the moment the new content lands,
            // so it is deleted rather than left on the private disk forever.
            'stored_file_will_be_deleted' => $lesson->isUploadedFile(),
            /*
             * ADR-028 §2.2 — is_downloadable is a property of the FILE, and
             * the file is being replaced. Left set, it would silently disable
             * the new type's completion gate (isMeasurable() returns false
             * while it is on), which is the combination TASK-188 §6.D2 names.
             */
            'is_downloadable_will_reset' => (bool) $lesson->is_downloadable,
            /*
             * ADR-029 §2.1 — "ANY lesson may carry questions, not only a
             * content_type=quiz one". So the quiz survives every direction of
             * this change, still attached, still blocking if it was blocking.
             * Reported so the UI can say so rather than leave the admin to
             * guess that it does not.
             */
            'quiz_id' => $lesson->quiz_id,
            'quiz_stays_attached' => $lesson->quiz_id !== null,
        ];
    }

    /**
     * TASK-188 §6.D — RETYPE. Human decision D2 (2026-08-13): a lesson's
     * content type can be changed after creation. ag-lead ruling D3: allowed
     * even when learner progress exists, provided the admin is told exactly
     * what happens to it and the change is audit-logged.
     *
     * Five things happen, and each is one of the four consequences §6.D2
     * demanded an answer for:
     *
     * 1. **The stored file is replaced, then deleted.** The Form Request makes
     *    a retype carry a complete content spec for the NEW type (a file for
     *    an upload, a content_ref for an external URL, neither for a quiz), so
     *    a lesson can never be left as a video player pointed at the old PDF.
     *    The orphaned file is removed after the commit.
     * 2. **The server-measured columns are cleared.** duration_seconds and
     *    page_count measure the OLD file (ADR-028 §2.3). Carried over they
     *    would become a denominator for content they never described — a stale
     *    page_count of 100 on a new 5-page document is the shape of silent
     *    corruption, so they are nulled and re-derived (pdfinfo inline,
     *    ffprobe via CompressUploadedVideo).
     * 3. **Learner progress is reset.** See resetProgress() below.
     * 4. **Completions are NOT touched** — ADR-028 §2.3 guard 1 / ADR-029 §3
     *    grandfathering. Nobody loses a lesson, or the BR-1 certification
     *    standing on it, because an admin changed the medium afterwards.
     * 5. **is_downloadable is re-stated, not inherited** (ADR-028 §2.2).
     *
     * The quiz is deliberately absent from that list: `quiz_id` is not
     * fillable and only QuizService may move it (ADR-030 §2.1), and ADR-029
     * §2.1 lets any content type carry one, so it needs no handling at all.
     *
     * @param  array<string, mixed>  $data
     */
    private function changeContentType(
        ModuleLesson $lesson,
        ModuleContentType $newType,
        array $data,
        ?UploadedFile $file,
        ?User $actor,
    ): ModuleLesson {
        // Snapshotted BEFORE any write: Model::update() re-syncs the original
        // attributes, so getOriginal() after it would answer with the new
        // values and the audit row would record a change from itself to itself.
        $oldType = $lesson->content_type;
        $oldSource = $lesson->source_type;
        $oldDownloadable = (bool) $lesson->is_downloadable;
        $oldPath = $lesson->isUploadedFile() ? $lesson->content_ref : null;

        $newSource = MediaSourceType::tryFrom((string) ($data['source_type'] ?? ''));
        $isUpload = $newSource === MediaSourceType::Upload
            && in_array($newType, ModuleContentType::uploadable(), true);

        if ($isUpload && ! $file) {
            // Unreachable through UpdateModuleLessonRequest (`file` is
            // requiredIf the new type is an upload) and stated anyway: without
            // it a future caller could commit a lesson whose content_ref still
            // points at the file this method is about to delete.
            throw ValidationException::withMessages([
                'file' => 'A new file is required when changing this lesson to an uploaded content type.',
            ]);
        }

        $data['source_type'] = $newSource?->value;
        // Cleared, never carried over — see (2) in the docblock.
        $data['duration_seconds'] = null;
        $data['page_count'] = null;
        $data['processing_status'] = null;
        // Re-stated, never inherited — see (5). Absent from the payload means
        // false, which is what the create path means by it too.
        $data['is_downloadable'] = (bool) ($data['is_downloadable'] ?? false);

        if ($newType === ModuleContentType::Quiz) {
            // A quiz lesson has no content_ref at all (ADR-009); the Request
            // prohibits one, so nothing in $data would clear the old value.
            $data['content_ref'] = null;
        }

        $lesson = DB::transaction(function () use ($lesson, $oldType, $oldSource, $oldDownloadable, $newType, $data, $file, $isUpload, $actor) {
            $lesson->update($data);

            if ($isUpload && $file) {
                // Written AFTER the type is persisted, so pageCountFor() below
                // measures against the NEW content_type rather than the old
                // one — it is the difference between a page count and pdfinfo
                // being pointed at an .mp4.
                $path = $newType === ModuleContentType::Video
                    ? $file->storeAs(
                        "academy-modules/{$lesson->company_id}",
                        StoredFileName::random($file),
                        self::DISK,
                    )
                    : $this->storeLessonFile($lesson, $file);

                $lesson->update([
                    'content_ref' => $path,
                    'page_count' => $this->pageCountFor($lesson, $path),
                    'processing_status' => $newType === ModuleContentType::Video
                        ? MediaProcessingStatus::Pending->value
                        : null,
                ]);
            }

            $resetCount = $this->resetProgress($lesson);

            /*
             * §6.D3(b) + CLAUDE.md §6 — "record every action that affects ...
             * status or certification". A lesson's completion gate is on the
             * BR-1 path, and this change moves that gate from one rule to
             * another (watch % to read %, or to the plain button) AND discards
             * the evidence learners had accumulated under the old one. Same
             * shape as QuizService::audit() and UserService::writeAudit().
             */
            AuditLog::create([
                'company_id' => $lesson->company_id,
                'actor_user_id' => $actor?->id,
                'action' => 'module_lesson.content_type_changed',
                'auditable_type' => ModuleLesson::class,
                'auditable_id' => $lesson->id,
                'old_values' => [
                    'content_type' => $oldType?->value,
                    'source_type' => $oldSource?->value,
                    'is_downloadable' => $oldDownloadable,
                ],
                'new_values' => [
                    'content_type' => $newType->value,
                    'source_type' => $lesson->source_type?->value,
                    'is_downloadable' => (bool) $lesson->is_downloadable,
                    // Not decoration: this is the number the confirmation
                    // dialog quoted to the admin, frozen next to their name.
                    'progress_rows_reset' => $resetCount,
                ],
                'ip_address' => request()?->ip(),
            ]);

            return $lesson;
        });

        // Best-effort cleanup of the file left behind, outside the
        // transaction — same reasoning (and the same non-atomicity) as the
        // in-place replace paths above.
        if ($oldPath && $oldPath !== $lesson->content_ref) {
            Storage::disk(self::DISK)->delete($oldPath);
        }

        if ($isUpload && $newType === ModuleContentType::Video) {
            CompressUploadedVideo::dispatch($lesson->id, ModuleLesson::class, self::DISK);
        }

        return $lesson;
    }

    /**
     * TASK-188 §6.D2/§6.D3 — the learner-progress consequence, and the one
     * judgement call in this change. Flagged for ag-lead rather than buried.
     *
     * `module_lesson_progress` stores raw positions in TYPE-SPECIFIC columns
     * (max_position_seconds for video, max_page/total_pages for PDF), and
     * LessonCompletionGate reads only the columns of the CURRENT type. So a
     * single retype does not misread anything: the new type's columns are
     * null, and the gate fails closed on them.
     *
     * What it does do is leave the old type's numbers lying in the row. Retype
     * back later — pdf → image → pdf, or a second correction of the same
     * mistake — and those numbers wake up as evidence against a document they
     * never described. A learner who had read 80 pages of the old file would
     * clear the gate on a new 5-page one without opening it, on the BR-1 path,
     * with nothing on screen to say so.
     *
     * So the rows are deleted. The learner keeps the completion if they had
     * earned one (grandfathering, untouched above); what they lose is the
     * bookmark and the partial credit, which measured content that no longer
     * exists. That is what makes "ความคืบหน้าของผู้เรียนจะเริ่มนับใหม่" a
     * sentence the confirmation dialog can honestly say — and the alternative,
     * keeping the rows, is the one that would need a sentence nobody can write.
     *
     * // TODO: CONFIRM (business rule) — ag-lead may prefer keeping the rows
     * // and closing the revival hole with a `content_changed_at` stamp the
     * // gate compares progress against. That is strictly less destructive and
     * // strictly more machinery; this takes the simpler side and says so.
     */
    private function resetProgress(ModuleLesson $lesson): int
    {
        // withoutGlobalScopes for the same reason as the impact counts above:
        // the actor is an admin, the rows belong to learners, and the lesson
        // id is server-resolved and already authorized (BR-6).
        return ModuleLessonProgress::withoutGlobalScopes()
            ->where('module_lesson_id', $lesson->id)
            ->delete();
    }

    public function delete(ModuleLesson $lesson): void
    {
        $lesson->delete();
    }

    public function disk(): string
    {
        return self::DISK;
    }

    /**
     * ADR-028 §2.1 — academy-lessons/{company_id}/{lesson_id}/{uuid}.{ext}
     * on the PRIVATE `local` disk. Never a public URL (§5 rule 6): the only
     * way to these bytes is ModuleLessonController::stream(), behind
     * ModulePolicy::view.
     */
    private function storeLessonFile(ModuleLesson $lesson, UploadedFile $file): string
    {
        return $file->storeAs(
            "academy-lessons/{$lesson->company_id}/{$lesson->id}",
            Str::uuid()->toString().'.'.$this->safeExtension($file),
            self::DISK,
        );
    }

    /**
     * ADR-028 §2.3 — measure a PDF lesson's page count with `pdfinfo` so
     * the completion gate has a denominator the CLIENT did not supply.
     *
     * Done inline rather than in a queued job (unlike video compression):
     * `pdfinfo` reads only the trailer and finishes in milliseconds, there
     * is no output file to write, and deferring it would leave a window in
     * which the gate silently falls back to the client-reported count.
     * Returns null for a non-PDF or a host without poppler — a documented,
     * already-tolerated deployment gap (SETUP.md / ADR-008), never an
     * upload failure.
     */
    private function pageCountFor(ModuleLesson $lesson, string $relativePath): ?int
    {
        if ($lesson->content_type !== ModuleContentType::Pdf) {
            return null;
        }

        return PdfPageCounter::count(Storage::disk(self::DISK)->path($relativePath));
    }

    /**
     * The stored extension is derived from the file's real mime type
     * (UploadedFile::extension() guesses from content), NOT from the
     * client-supplied name. The `mimes:` rule in the Form Request is what
     * actually rejects a .exe renamed to .pdf — this just makes sure the
     * name we persist can never carry an extension the mime check did not
     * agree with.
     */
    private function safeExtension(UploadedFile $file): string
    {
        // TASK-220 — the rule this method describes is now shared. Fifteen
        // other call sites carried the naive `getClientOriginalExtension()`
        // version and could produce a path ending in a bare dot. Kept as a
        // named method because the docblock above explains why it matters
        // HERE (the `mimes:` rule and this must not disagree), which a
        // shared helper cannot say on a specific caller's behalf.
        return StoredFileName::extensionFor($file);
    }
}
