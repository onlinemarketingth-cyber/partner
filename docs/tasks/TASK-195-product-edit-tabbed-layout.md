# TASK-195 — reorganize ProductEditView into 6 tabs

- **Owner:** ag-lead (spec) → ag-ui (frontend-admin only) → ag-qa
- **Date:** 2026-08-17
- **Human:** the product create/edit page has grown into 8 stacked cards (added over many
  sprints — ADR-008 and its many follow-ups) and now reads as cluttered. Asked for a proposal to
  group it into tabs. Presented two options (6-tab granular vs 4-tab coarse); **human picked the
  6-tab granular grouping.**
- **Related:** ADR-008 (original ProductEditView design), TASK-194 (just-added Affiliate override
  field, relocated by this task), the just-added `rate_type` toggle in the Commission Rules section
  (unaffected, just moves tabs). Purely a layout/navigation change — **no backend change, no field
  removed, no save behavior changed.**

---

## 1. Current state (confirmed by reading the file in full)

One flat vertical stack of `<section>` cards, `mt-4` spacing only, no tabs/accordion today.
Everything below "ข้อมูลสินค้า" is gated `v-else` on `isCreateMode` — i.e., invisible until the
product has an id (first save). Save behavior varies by section: some have one section-scoped
"บันทึก" button, most auto-save per action (upload/delete/toggle) with no button at all. **This
task must not change any of that — only which tab a section's card renders under.**

## 2. Target: 6 tabs

Use whatever tab-bar component/pattern this codebase already uses elsewhere for a multi-tab admin
screen (`CommissionPlansView.vue`'s own 6-tab bar is the closest precedent — same page, same
author, same visual language — reuse its exact tab markup/behavior, don't invent a new tab
component). Tabs 2–6 are disabled/hidden in create mode exactly as their content already is today
(`v-if="!isCreateMode"` on the tab itself, not just its content) — a new product must still fill in
Tab 1 and save once before anything else unlocks, unchanged from today's flow.

**Tab 1 — "ทั่วไป" (General).** Available in create mode (the only tab visible before first save).
Contents: everything currently in the "ข้อมูลสินค้า" card (name, brand, category, price,
commission_plan_type override + effective readout, pipeline_template_id override + stage-chip
preview) **MINUS** the two things relocated below (§2's Tab 2 and Tab 3) **PLUS** the small
"การแนะนำสินค้า" card (ปักหมุดแนะนำ toggle + sort order) folded in as a compact subsection at the
bottom — it's a 2-field toggle, doesn't need its own tab. Its own independent "บันทึก" (`savePin`)
stays exactly as-is, just relocated.

**Tab 2 — "คอมมิชชั่น" (Commission).** The existing "อัตราคอมมิชชั่น" card (rate rules list +
add/edit forms, including the `rate_type` %/fixed-amount toggle just added) **PLUS** the
"รูปแบบค่าคอมหัวหน้าทีม (Affiliate)" sub-field that TASK-194 just added inside the Basics card —
move it here instead, since it's fundamentally a commission-payout setting, not a basic product
attribute. It still only renders when `isEffectivelyAffiliate` is true (same condition, same
computed property, unchanged) — just rendered inside this tab's content instead of Tab 1's.

**Tab 3 — "บัตรกำนัลและจัดส่ง" (Voucher & Shipping).** The voucher_usage_quota /
voucher_validity_days (calendar + dropdown + presets) / requires_shipping fields — currently a
nested sub-block inside the Basics card's form — become their own tab. These are still part of
`saveBasics()`'s same single form-wide save (don't split them into a separate API call just because
they moved tabs — the human only asked for a visual reorganization, not a backend/save-boundary
change). If `saveBasics()` currently lives on one button under the Basics card, either (a) keep one
shared save button that's reachable/visible regardless of which of these tabs is active (e.g. a
persistent save bar), or (b) duplicate the same save button under both Tab 1 and Tab 3 bound to the
same `saveBasics()` call — ag-ui's call, pick whichever avoids a "how do I save this tab" dead end,
but do NOT split one form submission into two now-independent partial submissions (would change
error-handling / validation behavior in ways the human didn't ask for).

**Tab 4 — "รูปภาพและสื่อ" (Media).** "รูปสินค้า" (cover photos) + "รายละเอียดสินค้า" (detail media
gallery + product description, its own independent `saveDescription`) + the "ตั้งค่าคุณภาพวิดีโอ"
link (currently a stray link below the sales-materials card — relocate here since it's video/media
related, still just a navigation link to `ProductCatalogView`, not a new feature).

**Tab 5 — "สเปคสินค้า" (Specifications).** The "ไฟล์แนบสเปค (รูป/PDF)" card as-is (spec-attachment
gallery + spec description with its own `saveSpecDescription` + spec key/value list with its own
per-row saves and add form) — unchanged internally, just moved under this tab.

**Tab 6 — "สื่อการขาย" (Sales Materials).** The "สื่อการขาย" card (grouped file grid, share-link
modal) as-is, unchanged internally.

## 3. What must NOT change

- No field is removed, renamed (except tab labels), or has its validation changed.
- No save button's behavior, endpoint, or payload changes — only which tab's DOM it's rendered
  under.
- All existing modals (upload modals, preview modals, "More" overflow modals, share-links modal)
  keep working exactly as before, just triggered from within their (possibly relocated) card.
- `isCreateMode` gating: Tab 1 must remain fully usable pre-save; Tabs 2–6 remain unreachable until
  the product exists, matching today's behavior exactly (just expressed as disabled/hidden tabs
  instead of hidden cards).
- Deep-linking/URL: not required by this task — a local `ref` for the active tab is sufficient
  unless this codebase's existing tab pattern (`CommissionPlansView.vue`) already syncs to a query
  param, in which case match that for consistency.

## 4. Verification

- Every field/action inventoried in the research pass (see this task's originating investigation)
  is still present and functional after the move — nothing silently dropped in the refactor.
- Create mode: only Tab 1 selectable/visible, `saveBasics()` on first save behaves identically to
  today (redirects into edit mode / unlocks the rest, whatever it currently does — don't change
  it).
- Edit mode: all 6 tabs reachable, switching tabs preserves in-progress unsaved input in OTHER tabs
  (i.e., typing in Tab 2's add-rule form and switching to Tab 5 and back must not silently clear
  Tab 2's form — since all tabs stay mounted via `v-show`-style tabs is simplest/safest here rather
  than `v-if` remounting, unless this codebase's existing tab pattern already handles this some
  other way — check `CommissionPlansView.vue`'s own tab-switch behavior and match it).
- `vue-tsc --noEmit`, `eslint src` (frontend-admin only) clean.
- Live click-through: open an existing product, confirm all 6 tabs render their correct content,
  confirm a save action in each tab still round-trips correctly (spot-check at least the Commission
  rate add, the voucher validity date picker, and the description save).

## 5. Definition of Done

CLAUDE.md §9, plus: zero backend changes, zero fields/actions lost or behaviorally altered, tab
switching doesn't lose in-progress form state in other tabs, and the tab bar reuses this codebase's
existing tab-bar visual pattern rather than introducing a new one.
