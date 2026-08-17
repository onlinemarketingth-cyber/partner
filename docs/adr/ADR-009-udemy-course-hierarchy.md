# ADR-009: Udemy-Style Course Hierarchy (Section → Lesson → Formative Lesson Quiz)

- **Date:** 2026-07-22
- **Status:** Accepted — human-confirmed with "ปรับระบบให้เป็นมาตรฐาน Udemy แบ่งงานเป็น sprint ออกมา" / "จัดการเลย" (adjust the system to Udemy standard, split into sprints / go ahead). Implemented this session across Sprints A–F.
- **Author:** ag-lead

## Context

The human sketched the desired Academy content structure as:

```
cert tier
----  Module 1
---------clip 1
------------แบบทดสอบ 1 (quiz)
---------clip 2
------------แบบทดสอบ 2
----    Module 2
---------clip 1
---------clip 2
```

...and asked whether this matches international-standard LMS design ("ระบบสากลควรเป็นอย่างนี้หรือไม่"). Confirmed: this is standard Udemy-style **Section → Lesson → (optional) Lesson quiz** architecture. The system as it existed at that point was flat — one `Module` row *was* one content item (a single video/pdf/quiz/link directly on `modules`), so there was no way to group several clips/pages under one syllabus chapter, and no way to attach a short recall quiz to an individual clip. The human confirmed the redesign and gave the go-ahead to implement all sprints back-to-back without pausing for per-sprint confirmation.

## Decisions

1. **`Module` is repurposed as "Section"** — a pure grouping/ordering container under a `cert_tier` (and optionally a `product`). It keeps `company_id`, `cert_tier_id`, `product_id`, `title`, `sort_order`, `is_published`. All content-item fields (`content_type`, `source_type`, `content_ref`, `processing_status`, `xp_reward`) are **removed** from `modules` and moved to the new `module_lessons` table.

2. **New table `module_lessons`** — one row per actual content item (video/pdf/link, or `content_type=quiz`), many per Section, ordered by `sort_order`. Carries forward every ADR-007 video upload/embed field verbatim (`source_type`, `processing_status`, private-disk streaming via `module-lessons.stream`) — no new video design, just relocated.

3. **New formative lesson-quiz sub-system** (`module_lesson_quiz_questions`, `module_lesson_quiz_options`) — a `content_type=quiz` Lesson's content lives here instead of a `content_ref`. Deliberately **separate from the Exam engine** (Academy Sprint 1): a lesson quiz never gates BR-1 and never blocks progressing to the next lesson — it exists purely for recall/reinforcement. Schema and mutual-exclusion "at most one correct option" logic mirror `exam_questions`/`exam_question_options`/`ExamQuestionOptionService` exactly, so the two systems stay easy to reason about side by side despite being independent.

4. **`ModuleCompletion` is retargeted from `module_id` to `module_lesson_id`** — "completing" something now means completing one Lesson, not a whole Section. This is a breaking change to the append-only completion log, handled via a one-time data migration (see below), not a soft transition — acceptable since it is a one-way internal schema evolution, same trade-off already accepted elsewhere in this project's migration history (e.g. the `commission_ledger` referral-constraint migration).

5. **`Module.xp_reward` confirmed dead weight before moving it** — research before writing any migration found `GamificationService::awardXp()` resolves XP purely from the `gamification_rules` config table keyed by `source_type` (BR-5), never reading `$module->xp_reward`. So relocating `xp_reward` to `ModuleLesson` is a pure schema move with zero behavior change, not a new gamification decision.

6. **Policy reuse, no new Policy classes** — `ModuleLesson`/`ModuleLessonQuizQuestion`/`ModuleLessonQuizOption` have no dedicated Policy. Every controller/Form Request authorizes by walking up to the parent `Module` (Section) and checking `ModulePolicy::view`/`update`, exactly mirroring the pre-existing `ExamQuestionController` → `ExamPolicy` convention.

7. **`is_correct` masking carried forward unchanged**: `ModuleLessonResource` (Agent-facing, embedded lesson data) masks `is_correct` to `null` unless the caller `isSuperAdmin()`/`isCompanyAdmin()`, identical to `ExamResource`. This means the Agent Portal's lesson-quiz UI cannot self-grade from the API — see Sprint D note below.

8. **Data cutover is a single migration, uniform (non-driver-branched) shadow-table rebuild** for `module_completions` (`Schema::create(tmp)` → copy rows with `module_id` resolved to the new `module_lesson_id` → `drop` original → `rename`), avoiding `Schema::table()->change()`/`dropColumn()`/`dropForeign()` entirely since `doctrine/dbal` is not installed in this project. Every pre-existing `Module` row is wrapped into exactly one `Section` + one `ModuleLesson` carrying its original content, so existing syllabus data survives the cutover with no manual re-entry.

## Consequences

- **Route surface grew**: nested `/modules/{module}/lessons` (store), flat `/module-lessons/{moduleLesson}` (update/destroy/stream), nested `/module-lessons/{moduleLesson}/quiz-questions` (index/store), flat `/module-lesson-quiz-questions/{id}` and `/module-lesson-quiz-options/{id}` — one extra hop for every content operation that used to hit `/modules` directly.
- **Admin authoring UI** (`AcademyManagementView.vue`, "โมดูล" tab) is now a two-level accordion: Section CRUD at the top level, Lesson CRUD (including the video upload/replace UI carried over from ADR-007) nested inside each expanded Section, with a lesson-quiz authoring panel reusing the Exam question-bank UI pattern nested inside each `content_type=quiz` Lesson.
- **Agent Portal UI** (`AcademyView.vue`) renders Sections as group headers with their Lessons underneath; "mark complete" now targets a Lesson. A `content_type=quiz` Lesson exposes a "ลองทำแบบทดสอบทบทวน" self-check — because `is_correct` is masked for the Agent role and no attempt/grading endpoint was built for lesson quizzes (out of scope for this round), this is an ungraded, selection-only recall exercise, not a scored attempt. If graded lesson-quiz attempts are wanted later, that's a new feature to spec with the human, not something to infer here (Guardrail 2 — never assume business rules).
- **Admin progress dashboard** now rolls up completions at Lesson granularity and derives per-Section "X/Y lessons" progress from that, rather than reading a flat per-Module completion set.
- **Existing 2 seeded modules** (`AcademySeeder`) were converted to 1 Section + 1 Lesson each, preserving their original content/tier/product.

## Out of scope

- Grading/scoring for lesson quizzes (no attempt table, no pass/fail) — formative/self-check only, per decision 7 above.
- Drag-and-drop reordering of Sections/Lessons — `sort_order` remains a plain numeric field, same convention as every other reorderable list in this app.
- Sharing a `design-system/` component package between `frontend` and `frontend-admin` — out of scope for this ADR, tracked separately per ADR-003/CI-001/CI-002.
