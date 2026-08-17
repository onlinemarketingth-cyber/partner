# TASK-167 — Academy: one list page, three dedicated screens

- **Owner:** ag-lead (spec) → ag-dev (§3) + ag-ui (§4–§6)
- **Date:** 2026-08-11
- **Human:** *"อยากให้วิดีโอเปิดหน้าใหม่เหมือน PDF ปัจจุบันเปิดหน้าเดิม ทำให้หน้า academy รกมากและใช้งานยาก … บททดสอบท้ายบทเรียนก็สร้างหน้าใหม่ … หน้าประเมินก็สร้างหน้าใหม่เช่นเดียวกัน"*
- **Related:** ADR-009, ADR-028, ADR-029, ADR-031, TASK-152a, TASK-165

---

## 1. The problem

`AcademyView.vue` is a list plus **four inline expanders** — `expandedLessonId` (video /
image), `pdfLesson`, `expandedQuizLessonId`, `takingExamId`. Everything opens *in place*, so
the more course content a company publishes the less usable the screen becomes. PDF is the
only one already done right.

**Every expander becomes its own screen. The list page keeps only lists.**

## 2. Routes, not overlays — and the reason is the hardware back button

PDF today is a fixed overlay, not a route. Building the rest that way means **Android back
and iOS swipe-back exit Academy entirely** instead of returning to the list. This is a
phone-first app; that is not a detail.

| Route | Screen | Chrome |
|---|---|---|
| `/academy` | list: next step, sections + lesson rows, exams, certifications | bottom nav |
| `/academy/lessons/:id` | the content — video / PDF / image / link | **hidden** |
| `/academy/lessons/:id/quiz` | end-of-lesson quiz | **hidden** |
| `/academy/exams/:id` | certification exam (BR-1) | **hidden** |

**PDF renders inside the lesson screen, not as an overlay on top of it.** Two stacked
layers would reintroduce the problem this task removes.

## 3. ag-dev — the one backend gap

There is **no `GET /module-lessons/{moduleLesson}`** — only `PUT` and `DELETE`. Lesson data
currently arrives inside `GET /modules`. A lesson route cannot fetch itself, so a deep link
or a page refresh would land on an empty screen.

Add it. Same Policy as the existing single-record read, same Resource, and it must respect
everything the list already respects:

- `LessonAccessGate` — a locked lesson answers with its lock reason, exactly as the list does
- TASK-155 — a draft lesson (or a lesson in a draft Section) is **not** readable by an Agent
- `completion_is_automatic` (TASK-165) and `quiz_question_count` must be present, since the
  lesson screen decides what to show from them

Feature tests: own lesson OK; another company's → 404; draft → 404 for an Agent, readable
for an Admin; locked → the lock reason, not the content.

## 4. ag-ui — flow rules (human decisions, 2026-08-11)

### 4.1 Finishing the content

> **Amended 2026-08-11 (ag-lead ruling, rev.2).** As first written this section said
> "navigate as soon as the gate is met", and ag-dev implemented it and flagged it. The
> flag was right: **the gate trips at the configured threshold, not at the end** — 80% by
> default — so a learner was thrown out of a video with a fifth of it left to play. The
> human's "ไปบทถัดไป" answered *what happens when someone finishes*, not *what happens at
> 80%*. Corrected rule:
>
> - completion is still recorded silently and automatically — unchanged;
> - the next step is **OFFERED** as a button, and the learner presses it when ready;
> - the **one** automatic navigation is the video's `ended` event, and only if the lesson
>   was already completed. Nothing is left on screen to interrupt at that point.
>
> `LessonVideoPlayer` gained an `ended` emit for this, distinct from `flush` (which also
> fires on a pause). The destination is unchanged from what follows.

When the content gate is met and TASK-165 records the completion automatically:

- **the lesson has a quiz → go to the quiz screen**
- **no quiz → go to the next lesson that is actually open**

"Actually open" means the same predicate `firstIncompleteLesson()` already uses: published,
not optional, not locked. If there is none, return to `/academy`. **Never navigate to a
lesson the server would refuse** — that is the "button that cannot work" failure in
navigation form.

### 4.2 Finishing the quiz

Show the result and **stop**. The learner closes it themselves; closing returns to
`/academy` — not to the lesson they just finished.

Result wording is unchanged and non-negotiable: **pass/fail only**. No score, no
percentage, no which-answers-were-wrong (ADR-029 §3, ADR-028 §4).

### 4.3 Everything else that must survive

Locked/optional/drip states and their reasons on the list; the withheld measurement; the
TASK-152a vocabulary (`แบบทดสอบท้ายบทเรียน` vs `แบบประเมินผล`); automatic completion;
the button that remains only for non-measurable lessons.

## 5. App shell

`App.vue` computes `showChrome = !route.meta.public` — two states only. The three new
screens are a third: authenticated, but no bottom nav. Add a meta flag; do not abuse
`public`, which also controls the full-bleed background and would break theming.

## 6. Comments — the human asked for this explicitly

> *"จัดการคอมเมนต์ที่ไม่จำเป็นให้เหลือแค่ล่าสุดเท่านั้น"*

**Rule: keep the RULE and its reason. Delete the archaeology.**

- Delete: "this used to be X", "TASK-079 Phase 3 changed this", "the old ternary appeared
  here and in LoginView", before/after narration, and anything describing a state of the
  code that no longer exists.
- Keep, compressed to one or two lines: any rule a future reader could otherwise undo —
  the withheld percentage (ADR-028 §4), lock messages coming from the server verbatim, the
  denominator excluding optional and draft lessons, pass/fail-only quiz feedback. Cite the
  ADR and stop; the ADR holds the argument.

**Do not delete a comment you cannot replace with a shorter true one.** If a comment is the
only record of why a rule exists, compress it — do not drop it. That is the difference
between tidying and losing the reason someone will re-introduce a bug.

## 7. Risk and how to verify — read this before starting

This is the largest refactor in this codebase to date: ~1,900 lines becoming four files.
**ag-lead broke a smaller move than this earlier today** by slicing an array by line number
and silently deleting two whole cards while the build stayed green.

Before/after, enumerate and check off by name:

- every route reachable from `/academy`
- every lesson content type: uploaded video, embed video, uploaded PDF, external PDF/link,
  image, quiz-type lesson
- the four states on a lesson row: complete, in progress, locked-by-sequence,
  locked-by-drip, optional
- progress reporting still firing (TASK-165 depends on it — a silent break here stops
  completions entirely and nothing will fail loudly)
- exam take → submit → result
- certification list (the download button stays hidden per TASK-166)

`vite build` passing proves nothing about any of the above.

## 8. Acceptance criteria

- [ ] `/academy` renders lists only — no inline expander remains
- [ ] Video, PDF, image and link all play/render inside `/academy/lessons/:id`
- [ ] Hardware back / swipe-back returns to the list, not out of Academy
- [ ] Deep link and refresh on a lesson URL work (needs §3)
- [ ] §4.1 and §4.2 flows behave exactly as written
- [ ] A locked or draft lesson cannot be reached by typing its URL
- [ ] Bottom nav hidden on the three new screens, present on `/academy`
- [ ] `php artisan test`, `vue-tsc`, `eslint src`, `vite build` all clean
