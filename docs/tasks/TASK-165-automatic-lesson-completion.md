# TASK-165 — Completion is recorded, not declared

- **Owner:** ag-lead (spec) → ag-dev · **Date:** 2026-08-11
- **Human:** *"หน้า Frontend ทำไมถึงขึ้นปุ่มทำเครื่องหมายว่าเรียนจบให้ผู้ใช้ Agent"* → chose **auto-complete, remove the button**
- **Related:** ADR-028 (verified progress), ADR-029 (graded quiz), ADR-031, BR-1, BR-5

---

## 1. The contradiction being removed

ADR-028 §1: *"completion is EARNED, not asserted."* The button says
**"ทำเครื่องหมายว่าเรียนจบ"** — mark it as finished — which is the language of asserting.
The label contradicts the rule the code enforces.

How it got here: the button predates the gate. TASK-146 put `LessonCompletionGate` **in front
of** the button instead of **replacing** it. So today a learner opens a PDF they have not
read, presses a button that looks available, and gets a 422. This codebase already carries
my own comment, twice in that same file, that *"offering a button that cannot work is worse
than offering none"* — and then leaves this one standing.

## 2. Decision

**Where the system can measure, it records. Where it cannot, the learner still tells us.**

- **Verifiable** (uploaded video, uploaded PDF): completion fires automatically the moment
  the gate is satisfied. **No button.**
- **Not verifiable** (embed video with no progress signal, external link, and anything else
  `LessonCompletionGate` cannot measure): the button stays. ADR-028 §2.3 already made it the
  fallback for exactly this case; that half is unchanged.

## 3. ag-dev — implementation

### 3.1 The server decides which kind a lesson is; the client never re-derives it

`ModuleLessonResource` gains a boolean — name it something unambiguous such as
`completion_is_automatic`.

**The client must not compute this from `content_type` + `source_type`.** That predicate
already lives in `LessonCompletionGate`; a second copy in Vue is a copy that drifts, which is
the single most repeated defect in this codebase (see the `from`/`to` vs `color1`/`color2`
gradient mismatch, TASK-159). Expose the gate's own answer.

If the gate has no public "can I measure this lesson" method, add one and have `isEarned()`
use it, so there is one predicate, not two.

### 3.2 Fire on progress

`ModuleLessonProgressService::record()` (behind `PUT /module-lessons/{id}/progress`): after
recording, if the lesson is verifiable and `LessonCompletionGate::isEarned()`, call
**`ModuleCompletionService::complete()`** — not a direct `ModuleCompletion::create()`.

That method is the single write path and it carries the tenant check, the ADR-031 lock
check, the grandfathering early-return and the BR-5 XP award. Bypassing it silently drops
all four. XP must still be awarded exactly as it is today (`awardXp: true` — this is an
earned completion, not the ADR-028 §4.1 admin override).

### 3.3 Fire on quiz submission too — do not skip this

A lesson with `quiz_blocks_completion` is not earned by reading alone. A learner reads to
100%, no completion fires (correct — the quiz is unmet), then passes the quiz — **and no
further progress ping ever arrives**, because they have finished reading.

Without a trigger on the quiz-attempt path that learner is stuck at "not complete" forever,
having done everything asked. Fire the same check after a quiz attempt is graded.

### 3.4 Tell the client it happened

`PUT .../progress` currently answers `204`. Return a small body instead — e.g.
`{ "completed": true|false }` — so the row can flip without polling.

**This does not violate ADR-028 §4.** What §4 withholds is the MEASUREMENT (how far they
got, the threshold, their quiz score). Whether they are now complete is not withheld — the
lesson list already shows it.

### 3.5 Frontend (`/frontend/src/views/AcademyView.vue`)

- Hide the button when `completion_is_automatic`. Keep it otherwise, unchanged.
- On a `completed: true` response, mark the row done without a full reload.
- The blocked-completion message path (`completionBlockedMessages`) stays for the
  non-verifiable button. Do not surface a gate refusal for an automatic lesson — nobody
  pressed anything, so there is nothing to explain.

## 4. Acceptance criteria

- [ ] Reading an uploaded PDF to the configured percentage records a completion with **no
      user action**, and awards XP once
- [ ] Same for an uploaded video
- [ ] A `quiz_blocks_completion` lesson completes on **passing the quiz** after the content
      gate was already met — the §3.3 case, tested explicitly
- [ ] An embed video / external-link lesson **still shows the button** and still works
- [ ] No completion is ever recorded for a lesson locked by ADR-031 (sequence or drip)
- [ ] Repeat progress pings after completion do not duplicate the row or re-award XP
      (`wasRecentlyCreated` already guards this — prove it)
- [ ] Admin override (ADR-028 §2.3) unchanged, still awards no XP
- [ ] `php artisan test` green; `vue-tsc` + `eslint` + `vite build` clean on `/frontend`

## 5. Out of scope

- The percentage thresholds themselves (company config, BR-7)
- Showing the learner any progress figure — still withheld (ADR-028 §4)
- The admin progress dashboard
