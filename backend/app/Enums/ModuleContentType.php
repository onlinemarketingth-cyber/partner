<?php

namespace App\Enums;

// Academy modules.content_type — CLAUDE.md doesn't specify the exact
// content shapes, so this is the minimal reasonable set (ERD-001 §4).
// Widen this enum, don't add magic strings, if new types are needed.
enum ModuleContentType: string
{
    case Video = 'video';
    case Pdf = 'pdf';
    // ADR-028 §2.1 (human request, 2026-08-08: "support PDF + images") —
    // an uploaded still image as lesson content. Like Pdf it may also be
    // an external URL (source_type omitted); unlike Video it is never
    // compressed and has no processing_status of its own.
    case Image = 'image';
    case Quiz = 'quiz';
    case Link = 'link';

    /**
     * ADR-028 §2.1 — the content types that may carry an uploaded file on
     * our own private disk (source_type = upload). Video was the only one
     * before this ADR; pdf and image join it.
     *
     * One list, read by StoreModuleLessonRequest, UpdateModuleLessonRequest
     * and ModuleLessonService, so "which types can be uploaded" can never
     * drift between validation and storage.
     *
     * @return array<int, self>
     */
    public static function uploadable(): array
    {
        return [self::Video, self::Pdf, self::Image];
    }

    /**
     * ADR-028 §2.1 — the NEW file types (pdf/image), i.e. uploadable minus
     * video. They differ from video in storage path
     * (academy-lessons/{company}/{lesson}/ vs. the legacy
     * academy-modules/{company}/) and in never being sent to ffmpeg.
     *
     * @return array<int, self>
     */
    public static function uploadableFiles(): array
    {
        return [self::Pdf, self::Image];
    }

    public function isUploadableFile(): bool
    {
        return in_array($this, self::uploadableFiles(), true);
    }

    /**
     * ADR-028 §2.3 — the only two types the completion gate can verify
     * from recorded positions. image/link/quiz have no positional
     * tracking at all, so their completion stays the plain button.
     */
    public function hasPositionalProgress(): bool
    {
        return $this === self::Video || $this === self::Pdf;
    }
}
