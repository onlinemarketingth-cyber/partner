# TASK-188 — Academy builder: put the prose behind ⓘ, make the add-lesson button findable, let the content type change

- **Owner:** ag-lead (spec) → ag-dev (phase C backend) → ag-ui (phases A, B, C frontend) → ag-qa
- **Date:** 2026-08-13
- **Human:** *"คำประเภทนี้ ซ่อนไว้ที่ตัว I ทั้งหมด เพราะมันดูไม่ออกเลย"* + *"ผมหาปุ่มไม่เจอในการเพิ่มบทเรียน"*
- **Related:** ADR-021 (page header), ADR-023 (surface/ink tokens), ADR-028/029/030/031 (Academy)

---

## 1. What the audit found

**Prose:** the course-builder tab alone renders **32 blocks, ≈4,640 characters** of grey helper
text, permanently. Longest single block is **384 characters** under one checkbox. Four
explanations are written **twice** in different places (บทเสริม, อนุญาตดาวน์โหลด, ลิงก์ embed,
ลากจัดลำดับ) — two copies that can already disagree.

**There is no tooltip component in `frontend-admin/src` at all.** No `Tooltip.vue`, no popover, no
`aria-describedby` anywhere in the app. The only hover mechanism in use is the browser's native
`title=""` (239 occurrences, 36 of them in this one file), which **does not open on a touch
device** — and this Admin is used on tablets.

**Add-lesson:** exactly one button, `+ เพิ่มบทเรียน`, hidden behind **two** conditions —
absent from the DOM until a Section is selected, then hidden *again* while a lesson is selected.
It is also the **last** block of the card, after a settings panel carrying ~860 characters of
prose. That is why the human could not find it.

**Content type** (วิดีโอ / PDF / รูปภาพ / ลิงก์ / แบบทดสอบ) is chosen inside the create form and
**can never be changed afterwards** — the edit form has no such control and `editLessonForm`
carries no `content_type`. Choosing wrong today means deleting the lesson and rebuilding it, which
takes every learner's progress on it with it.

## 2. Human decisions (2026-08-13)

**D1 — hide ALL of it behind ⓘ**, including the consequence warnings. ag-lead recommended keeping
one-line consequence warnings visible and was overruled; that is recorded here so the trade-off is
known, not to be relitigated. **Because everything now lives behind the icon, the icon itself has
to be genuinely discoverable and genuinely tappable** — see §3.

**D2 — the content-type change is in scope for this task.**

**One deviation ag-lead is taking, flag it if you disagree:** the **20 user-visible internal
citations** (`ADR-029 §2.7`, `ERD-001 §Academy`, `(BR-1)`, `(BR-7)`, `(ADR-030 §2.5)` …) are
**deleted from user-facing copy, not moved into the tooltip**. They are references to our internal
documents; a customer reading them inside a popover is no better off than reading them on the
page. The *explanation* stays, the citation goes. Keep the reference in a **code comment** where
it is useful to us.

## 3. Phase A — the tooltip component (build it first; everything else depends on it)

New shared component in `frontend-admin/src/design-system/components/`.

**Requirements, each of which is a real failure mode of the obvious implementation:**

- **Opens on tap, not only hover.** Admin runs on tablets. A hover-only affordance means every
  warning in §1 becomes invisible on touch — which, given D1, would be the whole of the screen's
  guidance.
- **Keyboard reachable** — a real `<button>`, focusable, Enter/Space opens, Escape closes.
  `aria-describedby` or `aria-expanded` wired properly. Do not use a `<div @mouseenter>`.
- **Not the native `title` attribute.** It cannot be styled, cannot be tapped, and has a ~1s
  delay. Where you replace a `title` in this file, say so.
- **Must not be clipped.** The builder is a two-pane grid with `items-start` and no scroll
  container; a popover positioned inside a `overflow-hidden` card will be cut off. Test it on the
  narrowest column and at the bottom of the panel.
- Uses ADR-023's surface/ink tokens so it stays legible under a tenant's dark theme.
- The trigger is the existing `info` glyph (`Icon.vue:139`) — already in the icon set, used
  statically in two places today.

**Write its tests as a component spec**, including: opens on click, closes on Escape, closes on
outside click, and is reachable by keyboard alone.

## 4. Phase B — move the prose

**B1.** Every block listed in the audit moves behind an ⓘ next to the control it explains.
Sources are all in `AcademyManagementView.vue` (32 in the builder tab), plus `QuizLibraryPanel.vue`,
`QuizQuestionEditor.vue`, `LessonPreviewStrip.vue`.

**B2.** Strip the 20 internal citations per §2. Move each into a code comment on the same line so
we keep the provenance.

**B3.** De-duplicate the four repeated explanations. **One string, one place, both call sites read
it** — this repo has spent the week removing duplicated logic; duplicated user-facing copy drifts
the same way and is harder to notice. A shared constants module for the builder's copy is the
natural home.

**B4.** Where a value is explained *both* in a placeholder and in a paragraph ("เว้นว่าง = …"),
keep the placeholder and move only the paragraph. The placeholder is already the shortest possible
version of that sentence.

**B5.** What must stay visible regardless: the field label, the current effective value where one
is computed (e.g. "ค่าที่ใช้จริงกับบทเรียนนี้: 80%"), and any error. **A computed current value is
not an explanation — it is data.** Do not hide it.

## 5. Phase C — the add-lesson button

**C1.** `+ เพิ่มบทเรียน` must be reachable **without first selecting anything**. Removing the
second condition (hidden while a lesson is selected) is the minimum; ag-ui decides the placement,
but the test is: an admin who has just opened the screen can find it without clicking a Section
first.

**C2.** Consider a second entry point at the Section header row, next to the existing controls —
`+ เพิ่ม Section` already sits unconditionally at the top of the tab, and the two actions being in
completely different places is part of why one is findable and the other is not.

**C3.** Do not solve this by adding another paragraph explaining where the button is.

## 6. Phase D — content type after creation (D2)

**D1 — backend (ag-dev).** Allow `content_type` on the lesson update path. Read
`backend/app/Services/Academy/` and the existing lesson update Form Request first; the create path
already validates the same field and its dependent rules (upload vs link, `is_downloadable`
interactions) — **reuse that validation, do not write a second copy.**

**D2 — the consequences, which must be handled, not discovered by a customer.** Changing a
lesson's type can invalidate:
- the stored file (a PDF on a lesson now typed `video`)
- learner progress recorded under the old type's completion rule (watch % vs read %)
- an attached end-of-lesson quiz, depending on the new type
- `is_downloadable`, which the audit says cannot combine with the "ดู/อ่านให้ครบ" rule

**ag-dev must determine what actually happens to each** and report it before ag-ui builds the
confirmation. Do not guess in the UI copy.

**D3 — ag-lead's ruling, overrule me if the facts differ:** the change is **allowed even when
learner progress exists**, but it must (a) tell the admin exactly what will happen to that
progress in the confirmation dialog, naming the number of affected learners, and (b) write an
`audit_logs` row (`module_lesson.content_type_changed`) with old and new values. Blocking the
change outright would leave the admin in the same trap they are in today. **If ag-dev finds the
change would silently corrupt progress rather than merely invalidate it, stop and report** — that
becomes a human decision.

**D4** — use the existing `ConfirmDialog` (TASK-066); do not add a `window.confirm`.

## 7. Verification

- Component spec for the tooltip: tap, keyboard, Escape, outside-click, not clipped
- A test asserting the add-lesson affordance is present on first render with nothing selected
- Backend tests for the content-type change, including the audit row and each consequence in §6.D2
- Grep proving zero `ADR-0`, `ERD-001`, `(BR-` strings remain in **user-facing** template text in
  the Academy files (code comments are fine)
- Grep proving each de-duplicated string now has exactly one definition
- `npx vue-tsc --noEmit`, `npx eslint src`, `npm run build`, `npx vitest run` from
  `frontend-admin` — capture the baseline FIRST so anything red is attributable
- **Look at it in a browser at tablet width.** This is a task about whether a person can find
  things; a green test suite does not answer that. If no browser is available, say so plainly.

## 8. Definition of Done

CLAUDE.md §9, plus: the tooltip opens on touch and by keyboard, no internal citation is shown to a
customer, no explanation exists in two places, an admin can find the add-lesson button on first
render, and a lesson's content type can be changed with its consequences stated and audited.
