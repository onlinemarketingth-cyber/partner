# ADR-029 — Graded End-of-Lesson Quiz

- **Status:** Accepted · **Date:** 2026-08-09
- **Human:** *"ทำให้ตรวจได้จริง และนำมาใช้หลังจากดูแบบเรียนเสร็จให้ตรงกับวัตถุประสงค์"*
- **Amends:** ADR-009 §Out of scope (lesson-quiz grading), ADR-028 §2.3 (the completion gate)
- **Related:** BR-1, BR-5, BR-7, CLAUDE.md §6

---

## 1. Context

`module_lesson_quiz_questions` / `_options` have existed since ADR-009, and an admin can
author questions, options, and mark the correct one. **None of it does anything.**

`AcademyView.vue:785` is explicit:

```ts
const lessonQuizAnswers = ref<Record<number, number>>({}) // question_id -> option_id, local only
```

Answers live in browser memory, are wiped on reopen (`:791`), are never submitted, are
never graded, and `is_correct` is masked to `null` for the Agent role — so a learner who
answers correctly is told nothing. There is no attempt table.

The cost is not the missing feature; it is the **false affordance**. An admin spends real
time authoring questions that cannot affect anything, and a learner taps an option and
gets no response, which reads as a broken screen. Half-built is worse here than absent.

## 2. Decision

### 2.1 Any lesson can carry a quiz — it is a comprehension check, not a lesson type

`module_lesson_quiz_questions.module_lesson_id` already points at *any* lesson; only the
UI and `ModuleLessonResource`'s `when(content_type === Quiz)` ever restricted it. So a
**video or PDF lesson gains an end-of-lesson quiz**, which is what the human asked for and
what the feature was always named after.

`content_type = 'quiz'` remains valid for a standalone quiz lesson. Nothing is removed.

### 2.2 The quiz appears only after the content gate is met

ADR-028 already computes "watched ≥ 80% / read 100%". The quiz unlocks at that point.
Answering questions about a video you have not watched is not a comprehension check.

### 2.3 Grading is server-side, mirroring `ExamAttemptService`

New `module_lesson_quiz_attempts`. The client submits `{question_id: option_id}` only;
the server decides what is correct. `ExamAttemptService` is the existing, working
precedent — follow it rather than inventing a second grading style.

### 2.4 Pass mark — company default, per-lesson override (human: "1+2")

```
module_lessons.quiz_pass_percent  ?? academy_completion_settings.quiz_pass_percent (default 80)
```

Same most-specific-wins shape as commission scope and pipeline templates. Admin-editable
at both levels (BR-7). 80 is the human's stated default, not an invented number.

### 2.5 Unlimited retries

No attempt cap, no cooldown. This is a teaching device: the goal is that the agent ends up
understanding the material, not that we rank them. A cap would only generate support
tickets and an unlock screen nobody asked for. Every attempt is still recorded, so the
admin can see someone who took eleven tries.

### 2.6 Failing blocks completion — but the admin decides, per lesson

`module_lessons.quiz_blocks_completion` (bool). When true, the lesson is not complete
until the quiz is passed, and BR-1's certification path therefore runs through it. When
false the quiz is advisory and the attempt is still recorded for the admin.

Per-lesson rather than global because the same course legitimately mixes "you must know
this" with "here is some background".

### 2.7 Feedback: which answers were wrong — never which answer is right

The attempt result returns, per question, **whether the learner's own answer was correct**.
It must never return the correct option id or text, and `is_correct` stays masked on
options for the Agent role exactly as it is today.

**Stated plainly because it will be noticed:** §2.5's unlimited retries plus per-question
feedback means a determined agent can converge on the answers by elimination. That is
accepted. This quiz exists so the material is understood, and someone who brute-forces a
four-option question three times has still read the question three times. **The gate that
must resist gaming is the certification exam at the cert-tier level (BR-1), which is a
different system with a real pass score and recorded attempts.** Do not let anyone
describe this quiz as exam security.

## 3. Consequences

- The admin's authoring work finally has an effect — the main point.
- Support load rises where `quiz_blocks_completion` is on. The ADR-028 admin override
  (mark complete on a learner's behalf, audit-logged) already covers it; it must cover
  quiz-blocked lessons too.
- Existing quiz-type lessons keep working; they simply become gradable.
- **No existing `module_completions` row is re-evaluated** — same guarantee as ADR-028
  §2.3. Nobody loses a completion because a quiz was added afterwards.

## 4. Open — BR-7

1. Whether XP (BR-5) should be awarded for passing a quiz on top of the lesson's own XP.
   Not implemented; ask before adding — XP feeds promotion bonuses that pay real money.
2. Whether an admin should be able to see an individual learner's chosen answers, or only
   the score. PDPA-adjacent; score only until asked.
