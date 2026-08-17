# ADR-021 — Page header height budget (Agent Portal)

- **Status:** Accepted
- **Date:** 2026-08-03
- **Deciders:** KreangYot (human), ag-lead
- **Supersedes (in part):** the compact-row layout introduced in Sprint UI-WS-1.1 and amended by TASK-079 Phase 5 / TASK-085

## Context

The human set a hard constraint: the page header on **every** screen of the
Agent Portal must occupy **no more than 15% of the viewport height,
including padding**. Scope was confirmed to mean the in-page header only —
the app top bar and the bottom nav are excluded from the budget.

Measured before the change (mobile-emulated):

| Element | Height | % of 844px | % of 667px (iPhone SE) |
|---|---|---|---|
| App top bar | 57px | 6.8% | 8.5% |
| Page header row 1 — icon + title + action | 68px | 8.1% | 10.2% |
| Page header row 2 — tabs or search/filter | 69px | 8.2% | 10.3% |
| Bottom nav | 65px | 7.7% | 9.7% |
| **Total chrome** | **259px** | **31%** | **39%** |

The budget is 127px on a 844px phone and **100px on an iPhone SE**. Two
stacked rows total 137px, so the constraint was unreachable by tuning:
every padding and font reduction available across both rows recovers
roughly 24px. One of the two rows had to leave the page body.

## Options considered

**A. Promote the identity row into the app top bar.**
The top bar already exists, is always on screen, and showed the same
company logo on all 14 screens — pure duplication. Replacing that with
back + page title + page action is the standard native navigation bar.
Leaves the page body with only the tabs/filter row.
*Result: 45–70px (5.3%–8.3% of 844; 6.7%–10.5% of 667). Passes on every
screen and every phone size.*

**B. Collapse-on-scroll (large-title pattern).**
Full header at scroll top, shrinking to ~56px once scrolling.
*Rejected as the primary mechanism: the peak height (at rest, which is
what the constraint measures) still exceeds 15%. It also requires a scroll
listener driving a sticky, size-changing header — the same machinery whose
last outing (route-level `<Transition mode="out-in">`) blanked the entire
app on 2026-08-03. Not ruled out as a later enhancement, but it must be
spiked with a regression test first.*

**C. Trim paddings and font sizes only.**
`py-3`→`py-2`, icon 36→28, hide subtitle on mobile.
*Rejected as a standalone answer: lands at 13.3% on a 844px phone but
16.8% on an SE, i.e. fails the constraint on the smallest device we must
support. Kept as a complement to A.*

## Decision

**A + C.** The identity row moves to the app top bar; the page body keeps
only the tabs/filter row, trimmed.

Mechanism, chosen to avoid editing all 14 view files:

1. `src/stores/pageHeader.ts` — a Pinia store holding `icon`, `title`,
   `backPage`, `backLabel`, `active`.
2. `HeroHeader.vue` publishes its own props into that store on mount and
   on change, and `<Teleport defer>`s its `#actions` slot into
   `#page-header-action` in the top bar. Views keep passing exactly the
   props and slots they already passed.
3. `App.vue`'s top bar renders back + title + the teleported action, and
   falls back to the logo when no view has published a title (Home).

Constrained to mobile (`window.innerWidth < 640`). Desktop keeps the
existing compact/expanded header verbatim, because the expand chevron and
the KPI cards have nowhere to go in a 57px bar and desktop has the
vertical room anyway. This also guarantees the change cannot regress
desktop.

Store writes are guarded by a per-instance `Symbol` token: Vue mounts the
incoming view before unmounting the outgoing one, so an unguarded
`clear()` in the old view's `onUnmounted` would wipe the new view's title
and leave the bar showing the logo.

## Consequences

**Good**
- Header cost per screen drops from 137px to 0–70px; the 7 screens with no
  tabs row now spend **0px** of the budget.
- The back button lands in one fixed position on every screen instead of
  inside each page's card — native behaviour, and it retires the crowded
  `back + divider + icon + title + action + toggle` row that had to be
  patched twice (TASK-079 Phase 5, TASK-085).
- Titles stay visible while scrolling, since the top bar is sticky. The
  in-page title never was.

**Costs / risks**
- KPI cards remain hidden on mobile (they already were, in compact mode) —
  no regression, but also no path back without a new surface.
- The top bar now holds back + title + action + bell + avatar. On a 390px
  screen the title gets roughly 200px and truncates. Long titles
  ("คำสั่งซื้อ / รับชำระเงิน") rely on that truncation.
- Page actions keep their text labels in the bar. If a future screen needs
  a longer label it must shorten it or go icon-only; there is no room to
  grow.
- `HeroHeader` now has two rendering paths. Anything added to the identity
  row must be added to the top bar too, or it will silently vanish on
  mobile.

## Verification

Measured in-browser (mobile emulated at 390px):

| Screen | In-page header | % of 844 | % of 667 |
|---|---|---|---|
| /orders (back, no tabs) | 0px | 0% | 0% |
| /referrals (tabs) | 45px | 5.3% | 6.7% |
| /clients (search + filter) | 70px | 8.3% | 10.5% |

Back button confirmed working from the bar (`/profile` → `/`). Action
button confirmed teleported and clickable (`+ ลูกค้าใหม่`,
`+ สร้างคำสั่งซื้อ`, `+ Referral ใหม่`).

## Follow-ups

- Option B (collapse-on-scroll) stays open as a later refinement; requires
  a spike plus a regression test asserting the router outlet is non-empty
  after navigation.
- If a screen ever needs KPIs on mobile, they belong in the page body as a
  normal content card, not in the header.
