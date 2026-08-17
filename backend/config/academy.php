<?php

/*
 * ADR-028 §2.3/§4 — BR-7 platform-wide fallback used when a company has no
 * academy_completion_settings row yet
 * (AcademyCompletionSettingService::forCompany()).
 *
 * Both numbers are the human's stated decisions (KreangYot, 2026-08-08,
 * ADR-028 §4 "RESOLVED"), not invented values, so there is deliberately NO
 * `TODO: CONFIRM` here:
 *
 *   - video_watch_percent 80 — a video's last 20% is usually credits.
 *   - pdf_read_percent   100 — a skipped PDF page can be the page that matters.
 *
 * ADR-028 §4 records the asymmetry on purpose so nobody later "fixes" it.
 *
 * ADR-029 §2.4 adds a third, on exactly the same footing:
 *
 *   - quiz_pass_percent  80 — the human's stated default for the graded
 *     end-of-lesson quiz. Overridable per company
 *     (academy_completion_settings.quiz_pass_percent) and then per lesson
 *     (module_lessons.quiz_pass_percent), most specific winning — the same
 *     shape as commission rule scoping and the pipeline template chain.
 *     Stated by the human, so no `TODO: CONFIRM` here either.
 *
 * This file is only the zero-config starting point. Every company can
 * override both via PUT /academy-completion-settings (Company Admin /
 * Super Admin) — same "sane default, admin-editable, never a constant in a
 * Service body" role config/media.php and config/announcements.php play.
 */
return [
    'completion' => [
        'video_watch_percent' => (int) env('ACADEMY_VIDEO_WATCH_PERCENT', 80),
        'pdf_read_percent' => (int) env('ACADEMY_PDF_READ_PERCENT', 100),
        // ADR-029 §2.4 — the bottom of the quiz pass-mark resolution chain.
        'quiz_pass_percent' => (int) env('ACADEMY_QUIZ_PASS_PERCENT', 80),
    ],
];
