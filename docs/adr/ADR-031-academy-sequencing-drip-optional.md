# ADR-031 — Academy Sequencing: Reorder, Sequential Unlock, Drip, Optional Lessons

- **Status:** Accepted · **Date:** 2026-08-09
- **Human:** *"admin จะมีการจัดลำดับ หรือการ setup ในการแสดงให้ Frontend อย่างไรให้ครบตามระบบมาตรฐาน"* → *"ทำทั้งหมด"*
- **Builds on:** ADR-009 (Section → Lesson), ADR-028 (verified progress), ADR-029 (graded quiz)
- **Related:** BR-1, BR-5, BR-7, CLAUDE.md §6, §9

---

## 1. Context

Ordering exists at every level (`sort_order` on cert tiers, Sections, lessons, questions,
options) and `is_published` gates visibility. Four things a standard LMS has are missing:

1. **Reordering means typing numbers.** Inserting a lesson in the middle of twenty means
   renumbering every lesson after it, by hand, one edit form at a time. There is no drag
   handle anywhere in `AcademyManagementView.vue` (grep: zero `draggable`).
2. **No sequential unlock.** A learner can open lesson 5 without touching 1–4. This is the
   biggest gap: ADR-028/029 built a real per-lesson gate (watch 80%, pass the quiz) and
   nothing enforces order *between* lessons, so the course-level guarantee behind BR-1's
   Basic certification is still "they clicked around".
3. **No drip.** Publish is all-or-nothing and immediate.
4. **No optional lessons.** Every lesson counts equally toward progress; supplementary
   reading cannot be separated from required material.

## 2. Decisions (all four confirmed by the human, 2026-08-09)

### 2.1 Drag-and-drop reordering

A bulk endpoint per parent that takes the full ordered list of child ids and rewrites
`sort_order` in **one transaction** — never N separate PUTs, which would leave the list
half-reordered if the tab closed mid-way.

Applies to Sections within a cert tier and lessons within a Section. The numeric field
stays in the edit form as the accessible fallback; drag is added, not substituted.

### 2.2 Sequential unlock — lesson by lesson, switched per Section

**`modules.enforce_sequential`** (bool, default false).

When on, lesson *n* in that Section is locked until lesson *n−1* is **complete**
(ADR-028's real completion, not "opened"). Scope is **within the Section only** — the
toggle lives on the Section, so it cannot coherently mean anything wider.

Chosen over Section-to-Section because it is the stricter guarantee and the one that makes
the per-lesson gates add up to a course-level guarantee. Accepted cost, stated plainly:
**one lesson whose content is broken blocks everyone behind it.** The ADR-028 admin
override (mark complete on a learner's behalf, audit-logged) is the release valve, and it
must be reachable from the progress readout before this ships.

Default false. Turning it on is a deliberate act per Section, so no existing course changes
behaviour on deploy.

**Enforced server-side.** A locked lesson's content must not be streamable and its
completion must be refused — a client-side lock is decoration, and this one is on the BR-1
path (§6).

### 2.3 Drip — N days after the learner starts

**`modules.drip_days`** (nullable uint; null = available immediately).

Optional lessons and unlock rules compose: a Section can be both dripped and sequential.

**The anchor is the open question.** There is no enrollment table in this system
(deliberately — ADR-009 §Out of scope), so "when the learner started" has to be derived.
Implemented as the learner's **account approval date** (`users.approved_at`, falling back
to `created_at` for accounts that predate approvals), because it is the only date every
learner definitely has and "7 days after you joined" is a sentence a company can explain
to an agent.

Marked `// TODO: CONFIRM (business rule)` — if the human means "7 days after they open the
course", that needs a first-touch timestamp this system does not yet record, and that is a
schema decision, not a tweak.

### 2.4 Optional lessons — shown, not counted

**`module_lessons.is_optional`** (bool, default false).

Excluded from every progress denominator ("3/5 บท" counts required lessons only) and never
blocks a sequential chain. Displayed with a plain label so the learner knows it is there.

Counting them would contradict the word: a learner who skips an optional lesson would see
"4/5" forever and reasonably conclude the system is broken.

## 3. Consequences

- **Four new admin controls on one screen.** `AcademyManagementView` is already dense; the
  two rarely-used ones (drip, sequential) belong behind the Section's settings, not inline
  on every row.
- **The lesson list becomes stateful for the learner** — locked / available / done. The
  Agent Portal must show *why* something is locked, not just that it is. "ต้องเรียนบทก่อน
  หน้าให้จบก่อน" and "จะเปิดในอีก 3 วัน" are different problems for the learner.
- **Support load rises** the first time a company enables sequential unlock. Same shape as
  ADR-029's blocking quiz; same mitigation.
- Reordering rewrites `sort_order` for a whole sibling set at once — cheap, but it means
  two admins reordering the same Section concurrently will have last-write-wins. Acceptable
  (it is a display order, not money), and not worth a lock.

## 4. Open — BR-7

1. **The drip anchor** (§2.3) — approval date vs first course touch. **Still open.**
2. Whether a locked lesson should be **hidden entirely** or shown greyed with its title.
   Implemented as shown-and-greyed: hiding it makes the course look shorter than it is.
3. Whether `enforce_sequential` should also require the Section's own exam, where one
   exists. Not implemented; exams are cert-tier-level (ADR-009), not Section-level.

## 5. Amendment — TASK-155, drafts (ag-lead ruling, 2026-08-10)

Building §2.4's denominator exposed something §2.4 assumed was already true and was not:
**nothing on the learner path filtered `is_published` at all.**

`GET /modules` applied no filter of any kind, so draft Sections and draft lessons were
serialised to every Agent; the Vue client hid draft *lessons* via `visibleLessons()` and
showed the draft *Section's* header regardless; and `stream()` served a draft lesson's
bytes to anyone who guessed its id. The Section's publish toggle did nothing a learner
could observe. An admin drafting next quarter's material was publishing it.

This is the failure mode §2.2 already named — "a client-side lock is decoration" — on
routes that feed the BR-1 gate. Two decisions, neither a BR-7 business value (they follow
from what the flag already means):

- **Drafts are hidden, not greyed.** §4 item 2 chose shown-and-greyed for *locked*
  lessons, reasoning that hiding makes the course look shorter than it is. That reasoning
  is about material the learner will eventually reach. A draft may never exist, so it is
  filtered out of the list for Agents (`ModuleController::visibleTo()`), and `show()`
  answers 404 rather than 403 — to an Agent it does not exist.
- **A guessed id is refused at the gate.** `LessonLockReason::NotPublished`, checked
  first in `reasonFor()`, ahead of drip and sequence. One case closes stream, completion,
  progress and quiz-attempt at once because all four already consult that method. Its
  message says nothing about drafts.

The **Section's** flag counts as much as the lesson's: unpublishing a Section means its
contents are out of the course, and reading only the lesson flag would silently require an
admin to unpublish every lesson individually.

**This also resolves the `TODO: CONFIRM` in `AcademyProgressSummaryService`** ("should a
draft Section be excluded from the overall denominator?"). It was justified on the grounds
that this endpoint had to agree with `/modules`, which shipped drafts. That premise is
gone. Draft Sections are still *reported* — the admin outline needs them — but contribute
zero to both counts, and every completion query carries the same Section-level term so
numerator and denominator move together.

**Rollout consequence, stated plainly:** any company that left a Section unpublished while
learners were studying its lessons will see that material disappear from the Agent Portal
on deploy. **Completions already recorded are never revoked** (same grandfathering as
ADR-028 §2.3 guard 1), and because numerator and denominator drop together the fraction
stays honest. Check for draft Sections holding published lessons before deploying.
