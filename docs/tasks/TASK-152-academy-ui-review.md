# TASK-152 — Academy & Assessment UI: review, vocabulary alignment, admin builder

- **Owner:** ag-lead (this doc) → ag-ui (TASK-152b) · **Date:** 2026-08-10
- **Human:** *"วางแผนพัฒนา ui ให้รองรับการใช้งาน academy และการประเมณแล้วนำเสนอ Ui มาก่อนพัฒนา"* → *"ทำเลยครับ"*
- **Related:** ADR-009, ADR-028, ADR-029, ADR-030, ADR-031 · BR-1, BR-5, BR-7 · CLAUDE.md §7, §9

---

## 1. What the survey found — scope correction

I proposed three sprints. Surveying the two views first (`AcademyView.vue`, 1857 lines;
`AcademyManagementView.vue`, 3400 lines) shows **most of it is already built**, and saying
so is cheaper than rebuilding it.

| Proposed | Actual state |
|---|---|
| Learner: five lesson states with reasons | **Built.** `is_locked` + `lock_message` rendered verbatim, live drip countdown, `บทเสริม · ไม่นับในความคืบหน้า` pill, greyed-not-hidden per ADR-031 §4 |
| Learner: correct denominator | **Built.** `required_lesson_count` server-side; optional finished reported beside, never inside |
| Learner: Continue → first genuinely-open lesson | **Built.** `firstIncompleteLesson()` already excludes optional, draft and `is_locked` |
| Admin: drag-reorder sections and lessons | **Built.** Native HTML5 drag, handle-armed, one bulk PUT per sibling set |
| Admin: drip/sequential off the inline row | **Built.** `expandedSectionSettingsId` gear |
| Admin: outline + inspector two-pane | **Not built** — see §4 |
| Both: the two assessments told apart | **Not built, and actively drifted** — see §3 |

**Two corrections to my own proposal, recorded because the mockup is wrong and someone
will otherwise build from it:**

1. The learner mockup showed `ดูแล้ว 62%` on an in-progress lesson row. **That violates
   ADR-028 §4** (human decision, 2026-08-08: a learner who has not met the gate is not told
   how far they got). The current screen withholds it deliberately and correctly. The
   percentage must not be added. Withdrawn.
2. The mockup showed a whole-course progress bar in the header. The screen already carries
   one `ProgressRing` in the next-step card and nothing else, which is the earlier ruling
   ("no second progress bar"). No change.

## 2. Remaining work

**TASK-152a — assessment vocabulary (this task, done now).** §3.
**TASK-152b — admin outline + inspector (ag-ui, spec below).** §4.

---

## 3. TASK-152a — the two assessments are not distinguishable to a learner

**Owner:** ag-lead (small fix, done inline)
**Goal:** a learner can tell, before tapping, which of the two graded things they are about
to take.

There are two, and they are not the same object:

| | End-of-lesson quiz (ADR-029) | Certification exam (BR-1) |
|---|---|---|
| Scope | one lesson | one cert tier |
| Effect of failing | may block that lesson's completion (`quiz_blocks_completion`) | no certification → no selling rights (BR-1) |
| Feedback | pass/fail only, no score, no wrong answers (ADR-029 §3) | score against `passing_score` |
| Admin tab | แบบทดสอบท้ายบทเรียน | แบบประเมินผล |

The admin app was renamed to that vocabulary. **The Agent Portal never was.** It currently
calls the cert exam `แบบทดสอบใบรับรอง` and the lesson quiz `แบบทดสอบท้ายบท` — both open
with the same word, so the learner is being asked to distinguish two things by their
suffix. The two apps have drifted, which is exactly the failure mode CI-001/CI-002 warns
about for the duplicated design system.

**Ruling (ag-lead, vocabulary — not a business value, so not a BR-7 question):** one name
per object, identical in both apps.

- lesson quiz → **แบบทดสอบท้ายบทเรียน**
- cert exam → **แบบประเมินผล**

### Acceptance criteria

- [ ] No string in `/frontend` calls the cert exam `แบบทดสอบ...`; it is `แบบประเมินผล`
- [ ] The lesson quiz is `แบบทดสอบท้ายบทเรียน` wherever it is named in full
- [ ] The exam group header states the BR-1 consequence, not just the name
- [ ] The next-step card's action label distinguishes the two
- [ ] No score, pass percentage or wrong-answer count is added anywhere (ADR-028 §4, ADR-029 §3)
- [ ] `vue-tsc` + `eslint` + `vite build` clean on both apps

### Out of scope

- Backend field names, enum keys, API shapes. `content_type = 'quiz'` stays.
- The admin app's labels — already correct.

---

## 4. TASK-152b — admin course builder: outline + inspector

**Owner:** ag-ui
**Goal:** stop `AcademyManagementView.vue`'s โมดูล tab from expanding N accordions deep.
Left pane = the whole course outline, always visible, draggable. Right pane = settings for
whatever is selected.

**Related:** ADR-031 §3 ("`AcademyManagementView` is already dense"), CLAUDE.md §7 (small
single-purpose units), §9 (≤ 3 clicks).

**Input:** existing endpoints only. `GET /modules` (already `per_page` corrected),
`PUT /cert-tiers/{t}/modules/reorder`, `PUT /modules/{m}/lessons/reorder`,
`PUT /module-lessons/{l}`. No new API.

**Expected output:** the โมดูล tab renders a two-pane layout at `lg:` and above; below `lg:`
it falls back to the current stacked accordion (an inspector on a phone is a drawer, and
this admin app is used on a laptop).

### Acceptance criteria

- [ ] Selecting a lesson in the outline shows its settings in the inspector without
      collapsing the outline — the admin can see where they are while editing
- [ ] Drag-reorder still works, still one bulk PUT per sibling set, still restores the
      previous order and says why on failure
- [ ] Section-level settings (`enforce_sequential`, `drip_days`, publish) appear in the
      inspector when a **Section** is selected; lesson-level when a **lesson** is
- [ ] **The inspector saves on an explicit action, never on change.** Ruling below.
- [ ] A draft Section is visibly a draft in the outline
- [ ] Tenant isolation unchanged — this task adds no query
- [ ] `vue-tsc` + `eslint` + `vite build` clean

### Ruling — explicit save, not save-on-change

Asked as an open question; deciding it as ag-lead because it is a data-integrity question,
not a business value. `quiz_pass_percent` and `quiz_blocks_completion` are read at the
moment a learner submits (ADR-029). Save-on-change would let a half-typed `7` land as a 7%
pass mark while somebody is mid-attempt. The existing gear panels already save explicitly;
the inspector must not be the one surface that does not.

### Ruling — a draft Section with learners already in it

Asked as an open question; deciding it as ag-lead because the answer already follows from
rules we hold. Unpublishing hides the Section from the learner list **and** removes its
lessons from the denominator, but **completions already recorded are never revoked** — the
same grandfathering `ModuleCompletionService` applies at ADR-028 §2.3 guard 1 and ADR-029
§3. A learner mid-course sees the Section disappear and their fraction stay honest, because
both numerator and denominator drop together. No new code: this is what the current
`visibleLessons()` + `required_lesson_count` pair already does. Recorded so nobody "fixes"
it later.

### Out of scope

- The แบบทดสอบท้ายบทเรียน / แบบประเมินผล / ความคืบหน้าตัวแทน tabs
- Any change to what the learner sees
- The drip anchor — still open, see §5

---

## 5. Still open — BR-7, needs the human

**The drip anchor** (ADR-031 §2.3, §4 item 1). Currently `users.approved_at` falling back to
`created_at`, marked `// TODO: CONFIRM`. If "7 วันหลังเริ่มเรียน" means *first touch of the
course* rather than *account approval*, that needs a timestamp column this system does not
record — a schema decision, not a label change. **Not guessed. Not changed by this task.**

Two smaller ones inherited from ADR-031 §4 and still unanswered:

- Should `enforce_sequential` also require the Section's own exam? (Not implemented; exams
  are cert-tier-level, not Section-level.)
- Should badge metric `modules_completed_count` count optional lessons?
