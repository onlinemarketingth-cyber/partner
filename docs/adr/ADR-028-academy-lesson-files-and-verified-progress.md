# ADR-028 — Academy Lesson Files, In-App PDF Reading, and Verified Progress

- **Status:** Accepted
- **Date:** 2026-08-08
- **Human decisions:** KreangYot, 2026-08-08 — completion must be earned by actually reading/watching; downloadability is a per-file admin choice; support PDF + images; video must resume where it left off.
- **Amends:** ADR-009 §Out of scope, ADR-007 §Academy video
- **Related:** BR-1 (cert gate), BR-5 (XP), BR-7, CLAUDE.md §5 rule 6, §6, §9

---

## 1. Context

Three requests — upload files, video, read PDFs with scrolling — are in three completely
different states. Naming that honestly is most of this ADR's value.

| | State today |
|---|---|
| **Video** | Genuinely built end to end: upload → `CompressUploadedVideo` (ffmpeg) → per-company `video_processing_settings` → auth-checked stream → inline player in `AcademyView.vue:847`. |
| **Large-file upload** | Built (TASK-094: `chunked_uploads`, `ResolveChunkedUpload` middleware, prune command), and the Academy lesson routes already carry the middleware (`routes/api.php:527,529`) — but `AcademyManagementView.vue:369,427` still calls `api.postForm`, so an Academy upload goes as one request and dies at `post_max_size`. **A wiring gap, not a build.** |
| **In-app PDF reading** | `frontend-admin/src/design-system/components/PdfViewerModal.vue` already does continuous multi-page scroll via pdfjs — and is **imported by zero files**. `frontend/` has no pdfjs dependency and no PDF renderer at all. |

And underneath all three, the thing that makes them matter:

**`StoreModuleLessonRequest.php:60-66` prohibits `file` for every content type except
video.** `ModuleContentType` advertises `Pdf`, but a PDF lesson is a `content_ref` string —
in practice an external URL that `AcademyView.vue:543` opens in a new browser tab. No
upload, no storage, no tenant path, no authorization check, no in-app surface. The
Academy's own course material is the one class of file in this codebase that escapes
§5 rule 6 entirely.

### The deeper problem the human named

`ModuleCompletionService.php:30` writes a completion row on any POST. The learner-facing
trigger is a plain button (`AcademyView.vue:836`). **An agent can complete a 40-minute
video lesson without opening it**, then sit an exam and pass the Basic gate that BR-1
uses to unlock selling rights. The certification is only as meaningful as the learning
behind it, and right now there is nothing behind it.

---

## 2. Decisions

### 2.1 Lesson files are uploaded, stored privately, and streamed with authorization

`content_type` `pdf` and a new `image` gain `source_type = upload`, storing on the
`local` disk at `academy-lessons/{company_id}/{lesson_id}/{uuid}.{ext}` — mirroring
`ModuleLessonService`'s existing video path but adding the `lesson_id` segment that
`academy-modules/{company_id}/` currently lacks.

`ModuleLessonController::stream()`'s `abort_unless(video && upload)` widens to any
uploaded content type, keeping `$this->authorize('view', $moduleLesson->module)`.

**One file per lesson**, exactly like video. A lesson is ADR-009's atomic unit; multiple
attachments per lesson is a different shape (ordering, per-file progress, per-file
download flags) and belongs in its own ADR if it is ever wanted. `content_ref` stays
overloaded (path when uploaded, URL when embedded) — consistent with ADR-007, and
changing it now would touch video for no benefit.

External URLs remain supported for `pdf`/`link`. Upload is added, not substituted.

### 2.2 Downloadability is a per-file admin choice — and we will not overstate it

New `module_lessons.is_downloadable` (boolean). When false, the stream serves
`Content-Disposition: inline` and the UI offers no download control; when true, a
download button appears and the endpoint will serve `attachment`.

**Stated plainly, in the Admin UI copy as well as here: this is not DRM.** Once a browser
renders a PDF it holds the bytes, and any determined user can extract them. The flag
raises friction and records intent; it does not protect a document from someone who
wants it. Promising otherwise would be a lie told to a company about their own
confidential material, and they may make disclosure decisions on the strength of it.

### 2.3 Completion must be earned — with grandfathering and an escape hatch

New table **`module_lesson_progress`** (one row per user per lesson) tracking, per
content type:

- video: `last_position_seconds` (for resume) and **`max_position_seconds`** (for the gate)
- pdf: `last_page` (resume) and **`max_page`** (gate), plus `total_pages`
- image / link / quiz: no positional tracking

**The gate reads `max_*`, never `last_*`.** Scrubbing backwards or paging back must not
un-earn progress already made; conflating the two is the classic bug in this feature.

`ModuleCompletionService` gains a server-side check: the POST is rejected unless the
recorded `max_*` meets the threshold. **The client is not trusted to assert completion** —
today it entirely is, which is the whole defect (§6).

Three guards on the human consequences of tightening this:

1. **Existing `module_completions` rows are never re-evaluated.** Nobody who has already
   passed loses a certification because we changed the rule afterwards.
2. **An admin can still mark a lesson complete for an agent**, audit-logged. Files fail
   to render, devices break, a learner reads a printout. A rule with no override becomes
   a support queue.
3. **Thresholds are config, not constants** (BR-7) — see §4.

`is_downloadable = true` weakens the gate for that lesson by construction (the learner
can read it outside the app), so a downloadable file's completion falls back to the
button. This is stated rather than pretended away.

### 2.4 The PDF reader is copied into the Agent Portal, not shared

Per ADR-003 the two Vue apps duplicate `design-system/` deliberately. `PdfViewerModal.vue`
is copied to `frontend/`, `pdfjs-dist` added to `frontend/package.json`, and both copies
carry the CI-001/CI-002 keep-in-sync note.

It gains what the Academy needs and the Admin viewer never did: a page counter, an
`IntersectionObserver` reporting the furthest page reached, and a resume jump. It does
**not** gain zoom, search, thumbnails or a text layer — out of scope, and
`AttachmentLightbox.vue`'s iframe path stays as-is for the public share page.

`frontend/src/views/AcademyView.vue:543`'s `window.open(content_ref)` is replaced for
uploaded PDFs. It remains for genuinely external `link` lessons — those are somebody
else's page and always were.

### 2.5 Video resume needs range streaming to be worth having

`ModuleLessonController::stream()` returns `Storage::response()` (no HTTP Range), and
`useAuthenticatedMedia.ts:31-46` fetches the **whole file into a blob** before playback.
Seeking works — after downloading 200 MB. On a phone, on mobile data, "resume at 18:42"
would mean "wait for the entire video, then jump".

So range support is a **dependency of the resume feature, not a nice-to-have**, and is
scoped into the same sprint. The authorization check runs before any bytes are served,
unchanged.

### 2.6 Academy authoring uses the chunked uploader that already exists

`AcademyManagementView.vue` moves from `api.postForm` to `uploadInChunks` /
`MediaUploadModal`, both already built in `frontend-admin`. No new upload transport.

---

## 3. Rejected

- **A `module_lesson_files` table (many files per lesson).** Real cost — ordering, per-file
  progress, per-file download flags, a gallery UI — for a need nobody has stated. If a
  lesson needs three documents today, it can be three lessons.
- **Converting Office files to PDF server-side.** The human chose PDF + images; adding
  LibreOffice to the deploy for a use case not asked for is scope we would carry forever.
- **DRM / watermarking / disabling right-click.** Ineffective against anyone who cares,
  and it teaches users to trust a protection that is not there.
- **Trusting a client-reported completion percentage.** §6. The client reports positions;
  the server decides what they mean.

---

## 4. RESOLVED — human, 2026-08-08

All four were answered. No `TODO: CONFIRM` remains in this ADR.

| Question | Decision |
|---|---|
| Video completion threshold | **Admin-configurable per company, default 80%** |
| PDF completion rule | **Admin-configurable per company, default 100% of pages** |
| Max lesson file size | **20 MB, platform-wide.** No per-company ceiling — reuse `config('media.pdf.max_upload_mb')` rather than adding an `academy_*` setting nobody asked to vary. |
| Tell a blocked learner how far they got | **No.** |

**`academy_completion_settings`** (per company, BR-7): `video_watch_percent` default 80,
`pdf_read_percent` default 100. Both admin-editable — these are the seeded defaults the
human stated, not invented numbers, so no `TODO: CONFIRM`.

Two notes on the "don't tell them" decision, since it has a cost worth naming:

- The message must still be **actionable without being specific** — "กรุณาดูวิดีโอให้ครบก่อน
  จึงจะกดเรียนจบได้" tells a learner what to do; "ดูไปแล้ว 62% จาก 80%" tells them exactly
  how little they can get away with. Implement the former.
- **Expect support contacts** from learners who believe they finished and did not (a
  video paused in a background tab, a PDF page that never fully scrolled into view). The
  admin override (§2.3) is the answer, and Admin needs to *see* the recorded progress
  even though the learner does not. Build the progress readout into the Admin lesson
  view, not the learner's.

Note the asymmetry this creates: PDF at 100% is a strict rule — every page must be
reached — while video at 80% tolerates a trailing outro. That is a coherent choice (a
skipped PDF page can be the page that matters; a video's last 20% is usually credits),
and it is stated here so nobody later "fixes" the inconsistency.

### 4.1 Three ag-lead rulings during implementation (2026-08-08)

**A bookmark is not the withheld number.** ag-ui found that resume only survived within a
session, because the only progress read was Admin-scoped. Correct fix: add a
learner-scoped `GET` returning **`last_position_seconds` / `last_page` only** — never
`max_*`, never a threshold, never a percentage. What §4 withholds is *how close you are
to passing*; where you stopped reading is not that. Refusing a learner their own bookmark
would be privacy theatre paid for by the learner.

**No progress bar on lesson rows.** ag-ui declined to build one and was right: a bar
filled from reported positions is the withheld percentage rendered as geometry. The
non-numeric "กำลังเรียน" state chip stands. If a bar is ever wanted, §4 must be reopened
first — not worked around at the component level.

**An admin override awards no XP.** ag-dev flagged that the override path created the
same first completion and therefore fired BR-5 source (a). It must not. XP rewards
learning *behaviour*; an override records that we are accepting a lesson as done for an
operational reason (a broken file, a device that will not render, a learner who read a
printout). It is also not inert: XP feeds levels, badges, the leaderboard and promotion
bonuses that pay real money, so granting it on override creates a standing incentive to
ask for one. `ManualCertificationService` (TASK-058) already took this stance for manual
cert grants; this makes the two consistent. The completion row is still written and still
audit-logged — the learner is credited with the lesson, not with the effort.

---

## 5. Consequences

- Learners can finally read course material inside the app, under the same authorization
  rule every other uploaded file in the system already obeys.
- The Basic certification starts to mean something, which is the point of BR-1.
- **Support load will rise initially** — some agents will hit the gate and complain. The
  admin override (§2.3) is the pressure valve; make sure it is discoverable before
  rollout, not after.
- `frontend/`'s bundle grows by pdfjs (~1 MB). Load it lazily; the Academy is not the
  first screen.
- Progress rows are one per user per lesson — bounded and small, but they are now on the
  path of every lesson open, so index `(user_id, module_lesson_id)` from day one.
