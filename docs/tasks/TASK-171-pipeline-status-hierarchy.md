# TASK-171 — pipeline filter becomes a hierarchy: status → contextual stages

- **Owner:** ag-lead (spec) → ag-ui
- **Date:** 2026-08-11
- **Human:** *"ผมจะทำ Grouping ให้เพื่อให้ผู้ใช้ใช้งานได้ง่าย — Main Menu … Sub menu"*
- **Related:** TASK-169 Phase 3b, ADR-026, BR-4

---

## 1. What changes

Today `PipelineBoard` has **two independent filter rows** (TASK-169 Phase 3b): status
(`ทั้งหมด / รอดำเนินการ / เสร็จแล้ว`) and stage, both always visible, ANDed together.

It becomes a **hierarchy**: status is the main menu, stage is its **sub-menu**, and the
sub-menu's contents depend on which status is selected.

| Main | Sub-menu |
|---|---|
| **ทั้งหมด** | **none** — the stage row is not rendered at all (human's explicit instruction) |
| **รอดำเนินการ** | the stages that currently hold **open** deals |
| **เสร็จแล้ว** | the stages that currently hold **done** deals |

## 2. The sub-menu is COMPUTED, never a fixed list

The human's first draft assigned stages to buckets statically —
`รอดำเนินการ = [ลงทะเบียนสำเร็จ, รอนัดหมาย]`, `เสร็จแล้ว = [พบแพทย์, ชำระเงิน, นัดหมายเพิ่ม]`.
ag-lead flagged it and the human accepted the correction. Recording why, because this is the
**third** time today a fixed stage list has been proposed or found in this codebase:

- On the medical template, a deal at `พบแพทย์` still has `ชำระเงิน` and `นัดหมายเพิ่ม` ahead
  of it — it is **open**, not done.
- On a direct-sale template (`ลงทะเบียน → ชำระเงิน`), a deal at `ชำระเงิน` **is** done.
- So **the same stage key is open on one template and done on another.** No static
  assignment can be true, and ADR-026 guarantees templates differ.
- The draft also omitted `จัดส่ง` / `นัดใช้บริการ` / `ติดตามผล`, which exist (ADR-026 §5 Q1).

**The predicate is unchanged and already implemented:** a deal is done when it has no next
stage under **its own** template (`isOpen()` in `PipelineBoard.vue`, derived from BR-4 —
the ledger is written at Complete Payment and immutable after). Do not add a second one.

**A stage key may therefore legitimately appear under BOTH parents.** That is the truth, not
a bug, and the UI must not try to prevent it.

## 3. This deliberately REVERSES a Phase 3b decision

Phase 3b kept the stage vocabulary as the union over **all** referrals so that "tabs never
appear/disappear under the agent's thumb". That was sound while the two rows were
independent. It is now wrong: a sub-menu that ignores its parent is not a sub-menu. The
contents changing when the parent changes is the **expected** behaviour of a hierarchy, not
the surprise 3b was guarding against.

Update that comment rather than leaving two rules contradicting each other in one file.

## 4. Details to settle while building

- **Stale stage selection.** If the selected stage is not present under the newly selected
  status, reset to that status's own "ทั้งหมด". Never leave the board filtered to an empty
  set by a selection the agent can no longer see.
- **URL.** TASK-169 put the view mode in the query string. Decide whether status/stage
  belong there too and say what you chose — a shared "look at this" link is nice, but only
  the mode has a hard requirement (the `/pipeline` redirect).
- **Layout shift.** Selecting ทั้งหมด removes the second row entirely, moving the content
  up. Make it not feel broken.
- **Counts.** Sub-menu counts are within the selected status. The status row's own counts
  stay over all deals, matching the KPIs — do not make each axis re-count against the other.

## 5. Expected side benefit

The stage row is currently the union over every template — 5–8 tabs, horizontally scrolled,
and the screenshot the human sent shows the first label clipped to "กแล้ว". Scoping it to
one status shortens it, which should reduce or remove the clipping. Check on a 375px
viewport and report what it actually looks like; do not assume.

## 6. Acceptance

- [ ] Sub-menu is derived from the referrals actually in the selected bucket — no literal
      stage key anywhere in the status decision
- [ ] A mixed-template fixture where `complete_payment` appears under **both** parents
      renders correctly, and is covered by a test
- [ ] ทั้งหมด renders no stage row
- [ ] Stale stage selection cannot strand the agent on an empty board
- [ ] `vue-tsc`, `eslint src`, `vite build` clean; vitest green with new tests kept
