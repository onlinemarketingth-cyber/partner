# Sprint TASK-142 → TASK-148 — Academy: Lesson Files, PDF Reader, Verified Progress

- **Author:** ag-lead · **Date:** 2026-08-08 · **Driver:** ADR-028
- **Human request:** *"แผนพัฒนา Academy ในการ Upload ไฟล์ วิดีโอ และอ่านไฟล์ PDF สำหรับเลื่อนอ่าน"*

## Read this first

Of the three things requested, **video is already built and working**. Do not rebuild it.
The real work is: (1) Academy cannot store a non-video file at all, (2) the PDF reader
exists in the wrong app and is imported by nothing, (3) completion is an unguarded
button press.

Ordering matters. TASK-142 unblocks everything; TASK-146 (verified progress) must not
ship before TASK-144/145 give a learner any way to *earn* completion, or every learner
is instantly locked out.

---

### TASK-142 — Backend: lesson files (upload, store, stream)

```
Owner: ag-dev
Goal: An Academy lesson can hold an uploaded PDF or image, stored privately and served
      only after an authorization check.
Related: ADR-028 §2.1, §2.2, CLAUDE.md §5 rule 6, §6, BR-6

Input:
  - ModuleLessonService.php:21,31-41 (the video storage path — mirror it)
  - StoreModuleLessonRequest.php:55-66 (the rules that currently forbid this)
  - ModuleLessonController.php:54-64 (stream, currently video-only)

Expected output:
  - ModuleContentType gains `Image = 'image'`.
  - Migration: module_lessons.is_downloadable (bool, default false — ADR-028 §2.2).
  - Store/UpdateModuleLessonRequest: allow `file` when content_type ∈ {pdf, image} and
    source_type = upload. Mimes from config, NOT inline literals (BR-7).
  - ModuleLessonService stores at academy-lessons/{company_id}/{lesson_id}/{uuid}.{ext}
    on disk `local`. Note the lesson_id segment — the existing video path omits it.
  - stream(): drop `abort_unless(video && upload)`; allow any uploaded type. Keep
    authorize('view', $moduleLesson->module). Content-Disposition inline unless
    is_downloadable, then attachment.
  - ModuleLessonResource: expose is_downloadable; keep nulling content_ref for uploads.

Acceptance Criteria:
  - A PDF lesson can be created with a file and streamed back byte-identical.
  - Cross-tenant: another company's lesson stream → 403/404 (test it).
  - An unauthenticated request to the stream URL → 401. No public URL exists anywhere.
  - Existing video lessons behave identically — assert with the existing tests unchanged.
  - Uploading a .exe/.js renamed to .pdf is rejected by mime check, not extension.

Out of scope: multiple files per lesson; Office formats; thumbnails.
```

### TASK-143 — Backend: HTTP Range streaming

```
Owner: ag-dev
Goal: Make seek and resume actually usable.
Related: ADR-028 §2.5

Why: stream() returns Storage::response() with no Range support, and
useAuthenticatedMedia.ts:31-46 downloads the ENTIRE file to a blob before playback.
"Resume at 18:42" currently means "download 200 MB, then jump". This is a dependency
of TASK-147, not a nice-to-have.

Expected output:
  - Range/206 partial content on the lesson stream (and the same for any other private
    media stream that serves video — check ProductMedia and sales materials).
  - Authorization runs BEFORE any bytes are served. Unchanged, and assert it.
  - frontend/src/composables/useAuthenticatedMedia.ts stops blob-downloading video.
    Note the constraint that made blobs necessary: the stream needs the Sanctum cookie
    on a cross-origin request, so a bare <video src> will not authenticate. Solve it
    properly — do not weaken the auth check to make streaming easy.

Acceptance Criteria:
  - A range request returns 206 with correct Content-Range and only the bytes asked for.
  - An out-of-range request returns 416, not 200 with the whole file.
  - Seeking in a long video does not download preceding bytes (verify in the browser
    Network panel and report what you saw).
```

### TASK-144 — Agent Portal: in-app PDF reader with scroll tracking

```
Owner: ag-ui
Goal: A learner reads a lesson PDF inside the app, and the app knows how far they got.
Related: ADR-028 §2.4, ADR-003 (design systems are duplicated on purpose)

Input:
  - frontend-admin/src/design-system/components/PdfViewerModal.vue — ALREADY DOES
    continuous multi-page scroll. Copy it; do not write a new one.
  - frontend-admin/src/design-system/components/MediaPreviewModal.vue:73-90 — the
    pdfjs render that is actually live today
  - frontend/src/composables/useAuthenticatedMedia.ts — the authenticated-fetch pattern

Expected output:
  - pdfjs-dist added to frontend/package.json; LAZY-loaded (~1MB; Academy is not the
    first screen).
  - PdfViewerModal.vue copied into frontend/, with the CI-001/CI-002 keep-in-sync note
    in BOTH copies.
  - Adds: page counter ("หน้า 4 / 12"), IntersectionObserver reporting the furthest page
    reached, and a resume jump to the saved page.
  - AcademyView.vue: uploaded PDF lessons open in this reader, NOT window.open
    (currently line ~543). External `link` lessons keep window.open — those are somebody
    else's page.
  - Download button only when is_downloadable.

Acceptance Criteria:
  - Readable and scrollable at 375px. Test it, do not assume it.
  - A 50-page PDF does not freeze the tab — render lazily, not all pages at once.
  - Loading / error states: a failed fetch says so and offers retry; it must not show a
    blank white box.
  - Closing and reopening returns to the furthest page read.
```

### TASK-145 — Admin: Academy authoring uses the chunked uploader

```
Owner: ag-ui
Goal: Uploading lesson files stops failing on size.
Related: ADR-028 §2.6, TASK-094

Why: routes/api.php:527,529 already carry `resolve.chunked-upload`, but
AcademyManagementView.vue:369,427 posts with api.postForm — one request, dies at
post_max_size. The transport exists and is unused.

Expected output:
  - AcademyManagementView switches to uploadInChunks / MediaUploadModal
    (frontend-admin/src/api/client.ts:292, design-system/components/MediaUploadModal.vue).
  - File-type picker for pdf / image / video with the right accept attributes.
  - is_downloadable checkbox, with copy that does NOT overstate it — ADR-028 §2.2. Say
    "ซ่อนปุ่มดาวน์โหลด" not "ป้องกันการคัดลอก".
  - SETUP.md: document post_max_size / upload_max_filesize / client_max_body_size. It
    currently says nothing about TASK-094 at all.

Acceptance Criteria:
  - A file larger than post_max_size uploads successfully with visible progress.
  - Cancel mid-upload leaves no orphan (uploads:prune covers the rest).
```

### TASK-146 — Backend: verified progress + earned completion

```
Owner: ag-dev
Goal: Completion is earned, not asserted.
Related: ADR-028 §2.3, BR-1, BR-5, §6
BLOCKED BY: TASK-144 (do not ship the gate before learners can satisfy it)

Expected output:
  - Migration module_lesson_progress: company_id, user_id, module_lesson_id,
    last_position_seconds, max_position_seconds, last_page, max_page, total_pages,
    updated_at. Unique (user_id, module_lesson_id), index the same pair.
  - PUT /module-lessons/{lesson}/progress — throttled; the client reports positions
    only. The server decides what they mean.
  - max_* is monotonic: NEVER decreases. Scrubbing back must not un-earn progress.
    This is the classic bug in this feature — test it explicitly.
  - academy_completion_settings (per company, BR-7): video watch threshold, pdf rule.
    Seeded value marked // TODO: CONFIRM (business rule) — do not invent a percentage.
  - ModuleCompletionService rejects a POST that does not meet the threshold, with a
    Thai message. Reads max_*, never last_*.
  - is_downloadable = true → falls back to the button (ADR-028 §2.3, stated openly).
  - Admin override endpoint, audit-logged (§6 — it affects certification).

Acceptance Criteria:
  - EXISTING module_completions rows are never re-evaluated. Assert with a fixture of a
    pre-existing completion that would fail today's rule — nobody loses a certification
    retroactively.
  - A forged progress PUT claiming position 9999 on a 60s video is clamped to duration,
    not accepted.
  - Cross-tenant progress write → 403/404.
  - XP (BR-5) still awarded exactly once per lesson, on first completion only.
```

### TASK-147 — Agent Portal: resume + progress reporting

```
Owner: ag-ui
Goal: Video and PDF resume where the learner left off.
BLOCKED BY: TASK-143, TASK-146

Expected output:
  - Video: report position on a throttled interval and on pause/unmount; seek to
    last_position_seconds on open, with a visible "ดูต่อจาก 18:42" affordance rather
    than a silent jump — a silent jump feels like a bug the first time.
  - PDF: report furthest page; resume there.
  - Progress bar on each lesson row.
  - When completion is blocked, say how far they got (ADR-028 §4 item 4 — the human has
    not chosen between specific and vague; ASK before picking).

Acceptance Criteria:
  - Closing the app mid-video and reopening resumes within a few seconds of where it was.
  - Progress reporting does not fire a request per second — throttle and report the
    interval you chose.
```

### TASK-148 — QA gate

```
Owner: ag-qa
Acceptance Criteria:
  - §5 rule 6: every new file route rejects unauthenticated and cross-tenant access.
    Attempt to guess another company's lesson id and assert 403/404.
  - The gate cannot be bypassed by POSTing a completion directly (this is the entire
    point of the sprint — prove it with a raw request, not through the UI).
  - Grandfathering holds: a user with a pre-sprint completion keeps it.
  - Range requests: 206, correct Content-Range, 416 on garbage.
  - Mobile: PDF readable at 375px; a 50-page document does not hang the tab.
  - Report only results actually run (Guardrail 4).
```

---

## Sequencing

```
TASK-142 (files) ──┬── TASK-144 (reader) ──┐
                   └── TASK-145 (upload)   ├── TASK-146 (gate) ── TASK-147 (resume) ── TASK-148 (QA)
TASK-143 (range) ──────────────────────────┘
```

## Risks

- **R1 — the gate locks out real learners on day one.** Mitigated by grandfathering, the
  admin override, and shipping TASK-146 only after 144/145. Make the override
  discoverable *before* rollout.
- **R2 — pdfjs bundle size** on a mobile-first portal. Lazy-load; measure and report.
- **R3 — `is_downloadable` will be read as protection.** The UI copy is the mitigation.
  If anyone writes "ป้องกันการคัดลอก" in a label, reject the PR.
- **R4 — Range streaming touches the auth path.** The easy implementation is to make the
  file publicly reachable. That is a §5 rule 6 violation and an automatic rejection.
