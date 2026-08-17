# TASK-043: Admin Nav — 2-row IA (pillar + submenu) under "จัดการตัวแทน"

Human-confirmed scope (chat, 2026-07-23). Owner: ag-ui (nav + new page UI),
ag-dev (new grouped commission endpoint — confirmed missing, see below).

## Problem

`frontend-admin/src/design-system/components/AdminNavigation.vue`'s active-highlight
logic is `activeName === item.name`, an exact match against `route.name`. Three
existing sub-pages (`announcements`, `reward-center`, `agent-promotions`) were never
added to `navItems`, so visiting them highlights nothing in the top bar — confirmed
by reading the file, not assumed. Human asked for the fix to follow the same
pattern used in `/Users/ken/Code/medical-saas/resources/js/components/TopNavigation.vue`
(read in full): a two-row nav — row 1 = pillars, row 2 = the active pillar's
sub-menu — where every real route belongs to exactly one pillar's `subMenus`,
so highlighting is always resolvable.

## Scope

**1. Data-model restructure (`AdminNavigation.vue`)**
Every existing top-level nav item becomes a pillar object with a `subMenus` array
(most pillars keep a single-entry `subMenus: [self]`, unchanged behavior). Active-pillar
check changes from `route.name === item.name` to "route.name is one of this pillar's
subMenus" — this is the actual fix, and it generalizes to any future sub-page under
any pillar, not just this one.

**2. New submenu row under "จัดการตัวแทน" pillar only**
5 sub-items, shown only when that pillar is active:
| # | Label | Target |
|---|---|---|
| 2.1 | จัดการตัวแทน | existing `agent-management` route, unchanged |
| 2.2 | ค่าคอมมิชชั่น | **new** route/page — per-agent commission summary (see below) |
| 2.3 | ข่าวสาร | existing `announcements` route |
| 2.4 | Promotion | existing `agent-promotions` route |
| 2.5 | ศูนย์รางวัล | existing `reward-center` route |

**3. New page — "ค่าคอมมิชชั่น" (per-agent commission summary)**
Confirmed via reading `CommissionLedgerController`/`CommissionLedgerResource`: no
grouped-by-agent endpoint exists today — `index()` only returns a flat paginated
list of individual ledger rows (identical data to the existing `/commission-management`
screen). Human confirmed this must be a genuinely different page, not a relabeled
link to the existing one. Proposed shape (ag-lead design proposal, flagged to human
as adjustable, not a guessed business rule — this is IA/display only, no money
calculation logic changes): one row per agent showing `total_paid_satang`,
`total_pending_satang`, `entry_count`, click-through to a per-agent detail (reuse
`/commission-management`'s existing detail list, filtered by that agent).

**4. Existing top-level "Commission" and "แผนคอมมิชชั่น" pillars**
Untouched — human confirmed keep both as-is; item 2.2 is an additive shortcut,
not a replacement.

## Acceptance criteria

- [ ] Visiting `/announcements`, `/reward-center`, `/agent-promotions` highlights
      "จัดการตัวแทน" in the top row (previously highlighted nothing).
- [ ] Second row appears only under the "จัดการตัวแทน" pillar, 5 items, Thai labels
      per the table above, active sub-item visually distinct from the rest.
- [ ] New per-agent commission summary page + backend endpoint: tenant-scoped
      (company_id), Agent role still narrowed to own rows only (same rule as
      existing `CommissionLedgerController::index()`), money as integer satang (BR-3).
- [ ] The other 8 top-level pillars behave exactly as before — no visual/behavioral
      regression (verify via live click-through, not just code review).
- [ ] `npx vue-tsc --build` + eslint clean.
- [ ] Existing "Commission" / "แผนคอมมิชชั่น" top-level items unchanged.

## Out of scope

- Any change to commission calculation logic (BR-2/BR-4) — this is a read-only
  display aggregation on top of existing `commission_ledger` rows.
- Restructuring any other pillar's submenu — only "จัดการตัวแทน" gets real
  sub-items in this pass.
