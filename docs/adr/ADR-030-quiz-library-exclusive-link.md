# ADR-030 — Quiz Library, Exclusively Linked to One Lesson

- **Status:** Accepted · **Date:** 2026-08-09
- **Human:** *"มีแบบที่ link แบบทดสอบเดิมได้ไหม และแบบทดสอบไหน link แล้วจะไม่สามารถ link บทเรียนอื่นๆ ได้"*
- **Amends:** ADR-029 §2.1 (questions hang off the lesson), ADR-009
- **Related:** BR-6, BR-7, CLAUDE.md §6, §7

---

## 1. Context

`module_lesson_quiz_questions.module_lesson_id` ties every question directly to one
lesson. There is no "quiz" object, so an admin can only ever type questions in place —
there is nothing to pick from and nothing to prepare in advance.

The human wants a **library**: author a quiz first, attach it to a lesson later. Possibly
by a different person, before the lesson content even exists.

### The constraint, and what it means

The human also specified: **a quiz that is linked to a lesson cannot be linked to another.**

That was worth checking, because the usual reason to build a quiz library is *reuse* —
and this rule forbids reuse. Confirmed with the human: the goal is **preparation, not
reuse**. So this is a staging area, not a shared bank, and the exclusivity is the point
rather than a limitation to be worked around later.

Recording it plainly so nobody "improves" it into a many-to-many six months from now and
quietly breaks the mental model: **one quiz belongs to at most one lesson, forever, until
it is explicitly unlinked.**

## 2. Decision

### 2.1 A `quizzes` table owns the questions

```
quizzes            id, company_id, title, timestamps, softDeletes
module_lesson_quiz_questions.quiz_id   (replaces module_lesson_id)
module_lessons.quiz_id                 nullable FK, UNIQUE
```

**The `UNIQUE` on `module_lessons.quiz_id` is the whole rule**, enforced by the database
rather than by a Service that could be bypassed. Two lessons cannot claim one quiz even
under a race, a seeder, or a console command. Validation in the Form Request and Service
still exists so the admin gets a 422 instead of a driver error — but the constraint is
what makes it true.

### 2.2 Existing questions migrate automatically

Human decision: each lesson that has questions gets **one quiz created for it**, named
after the lesson, with its questions moved across and `module_lessons.quiz_id` set. No
data is lost, no admin has to do anything, and every existing lesson behaves exactly as
it did the moment before.

### 2.3 Unlinking returns the quiz to the library

A lesson can drop its quiz; the quiz becomes attachable again. **Attempts are not
affected** — `module_lesson_quiz_attempts.module_lesson_id` stays pointed at the lesson,
because an attempt is a record of *a learner doing a lesson*, not of a quiz in the
abstract. If the quiz is later attached elsewhere, the old attempts still correctly belong
to the old lesson, and `passed` is already frozen at attempt time (ADR-029).

### 2.4 A linked quiz cannot be deleted

Unlink first. Deleting under a lesson's feet would silently remove its completion gate —
and where `quiz_blocks_completion` is on, that gate sits on the BR-1 certification path.
Silently loosening a certification requirement is not an acceptable failure mode for a
mis-click.

### 2.5 The picker only offers what is actually available

The lesson's quiz selector lists **unattached quizzes in the same company**, plus the one
currently attached. A quiz already taken by another lesson must not appear as a choice
that then fails — the UI should not teach the rule by rejecting the user (§9).

## 3. Consequences

- One more concept for an admin to learn. Mitigated by keeping "create a new quiz right
  here" as the default path — the library is an option, not a required detour.
- The library will accumulate orphans (authored, never attached). Show them as such;
  do not auto-delete anything an admin typed.
- `module_lesson_quiz_questions` changes its owner column. Every read path must move with
  it in the same change — a missed one silently shows an empty quiz.

## 4. Open — BR-7

1. Whether a Company Admin may see/attach quizzes authored by another admin in the same
   company. Assumed yes (company-scoped, BR-6); ask if it should be per-author.
2. Whether unlinking should warn when learners have already attempted the lesson.
   Recommended, not specified.
