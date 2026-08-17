<?php

// ADR-007 — platform-wide fallback used by VideoProcessingSettingService
// when a company has no video_processing_settings row (BR-7: still
// admin-editable — a company Admin can override any of these three
// values for their own company via the Admin UI; this file only supplies
// the zero-config starting point, same role config/gamification.php-style
// files play elsewhere in this codebase for "sane default, never
// hardcoded into a Service class body").
return [
    /*
     * TASK-093 (2026-08-03) — external binary paths.
     *
     * Both jobs used to hard-code the bare command name, which only
     * resolves if the binary is on the web user's $PATH. Verified on the
     * production host (Hostinger shared, Business plan): `which ffmpeg`
     * returns nothing. Shared hosting will not install system packages,
     * but it DOES allow running a static build from the account's own
     * home directory — which needs an absolute path, hence these keys.
     *
     * Leave as the bare name for any environment where the binary is on
     * $PATH (local MAMP, a VPS); set FFMPEG_PATH / PDFTOPPM_PATH in .env
     * to an absolute path on shared hosting, e.g.
     *     FFMPEG_PATH=/home/u995267164/bin/ffmpeg
     *
     * Both jobs already degrade gracefully when the binary is missing
     * (original file kept, processing_status=failed) — this only makes
     * "present but not on $PATH" a solvable case instead of a dead end.
     */
    'binaries' => [
        'ffmpeg' => env('FFMPEG_PATH', 'ffmpeg'),
        'pdftoppm' => env('PDFTOPPM_PATH', 'pdftoppm'),
        /*
         * ADR-028 §2.3 — `ffprobe` ships alongside `ffmpeg` in every
         * standard build, and is how CompressUploadedVideo learns a
         * lesson video's real duration. That duration is the DENOMINATOR
         * of the video completion gate: without a server-known duration
         * there is no honest percentage to check a learner's
         * max_position_seconds against.
         *
         * Same TASK-093 shared-hosting story as the two above — set
         * FFPROBE_PATH in .env when the binary is not on $PATH.
         */
        'ffprobe' => env('FFPROBE_PATH', 'ffprobe'),
        /*
         * ADR-028 §2.3 — `pdfinfo` (poppler-utils, already a deployment
         * requirement for ADR-008's spec thumbnails) is how an Academy PDF
         * lesson's page count becomes SERVER-KNOWN rather than
         * client-asserted. That distinction is the difference between a
         * completion gate that can be forged by reporting "this document
         * has 1 page" and one that cannot.
         *
         * GeneratePdfThumbnail predates TASK-093 and still hard-codes the
         * bare name; this key exists so new callers do not repeat that.
         */
        'pdfinfo' => env('PDFINFO_PATH', 'pdfinfo'),
    ],

    /*
     * TASK-094 — chunked upload (see the chunked_uploads migration).
     *
     * `chunk_mb` is human-confirmed at 5MB (2026-08-03). It must stay
     * comfortably under the SMALLEST post_max_size the app will ever run
     * behind — the whole point is that no environment has to raise it.
     * The frontend reads this value from /upload-settings rather than
     * hardcoding it, so lowering it here (slow mobile networks) takes
     * effect without a redeploy.
     *
     * `stale_hours` is how long an abandoned .part file survives before
     * the uploads:prune command deletes it — an upload interrupted by a
     * dropped connection leaves one behind every time.
     */
    'upload' => [
        'chunk_mb' => (int) env('MEDIA_UPLOAD_CHUNK_MB', 5),
        'stale_hours' => (int) env('MEDIA_UPLOAD_STALE_HOURS', 24),
    ],

    'video' => [
        'max_upload_mb' => (int) env('MEDIA_VIDEO_MAX_UPLOAD_MB', 200),
        'target_resolution' => env('MEDIA_VIDEO_TARGET_RESOLUTION', '720p'),
        'target_bitrate_kbps' => (int) env('MEDIA_VIDEO_TARGET_BITRATE_KBPS', 2500),
        // Allow-listed upload mime types for a video source_type=upload,
        // shared by Product media, Sales materials, and Academy modules.
        'allowed_mimes' => ['mp4', 'mov', 'webm', 'm4v'],
    ],

    // ADR-008 — Product spec attachment gallery (product_spec_attachments).
    // Same "sane default, admin-editable, never hardcoded into a Service
    // class body" role as the `video` key above.
    //
    // ADR-028 §4 (human decision, 2026-08-08) — this same ceiling is now
    // ALSO the ceiling for an Academy lesson file (pdf or image): "20 MB,
    // platform-wide. No per-company ceiling — reuse
    // config('media.pdf.max_upload_mb') rather than adding an academy_*
    // setting nobody asked to vary." Do not add one.
    'pdf' => [
        'max_upload_mb' => (int) env('MEDIA_PDF_MAX_UPLOAD_MB', 20),
        'allowed_mimes' => ['pdf'],
    ],

    /*
     * ADR-028 §2.1 — an Academy lesson may now be an uploaded IMAGE as
     * well as a PDF, so the image allow-list needs a home outside a Form
     * Request body (BR-7: no inline literals).
     *
     * Deliberately NO `max_upload_mb` key here: ADR-028 §4 resolved the
     * lesson-file ceiling as the platform-wide `pdf.max_upload_mb` above.
     * Adding a second, silently different image ceiling would be exactly
     * the "value nobody asked to vary" that decision rejected.
     */
    'image' => [
        'allowed_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],
];
