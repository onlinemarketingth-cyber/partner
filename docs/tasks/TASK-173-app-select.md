# TASK-173 — replace every native `<select>` in the Agent Portal with a themed control

- **Owner:** ag-lead (spec) → ag-ui
- **Date:** 2026-08-12
- **Human:** *"แก้ selectbox ทั้งระบบ frontend ให้แสดงผลแบบ ui กำหนดเอง ไม่ใช้แบบ standard ทำให้เล็กมองไม่เห็น"*
- **Related:** ADR-018 (per-company theming), ADR-023 (input surface/ink tokens), TASK-085 (FilterSheet), TASK-098

---

## 1. Why CSS cannot fix this

The `<select>` element itself is already themed — `bg-surface-input`, `text-ink-input`,
`border-line-input`, all resolving to the tenant's colours (ADR-023). **The dropdown it
opens is not part of the page.** It is rendered by the operating system, and no browser
exposes it to CSS.

So on a dark tenant theme the field is dark and the list that opens over it is a small
white OS box with default-size text — which is what the human photographed. **This is the
one place ADR-018's white-labelling silently stops working**, and it cannot be closed by
styling. The control has to be ours.

## 2. Scope (human decision, 2026-08-12)

**Agent Portal (`frontend/`) only — 21 selects across 8 files.** The admin app's 96 are out
of scope for now; the same component can be copied over later (ADR-003 — the two apps do
not share a package, so it is a copy, not an import).

| File | selects |
|---|---|
| `design-system/components/BuddhistDateInput.vue` | 6 |
| `views/ClientsView.vue` | 5 |
| `design-system/components/ReferralCreateForm.vue` | 4 |
| `views/ProductBrowseView.vue` | 2 |
| `views/OrdersView.vue`, `AffiliateLinksView.vue`, `AffiliateLeadCaptureView.vue`, `components/CoAgentEditor.vue` | 1 each |

**`BuddhistDateInput` is IN** (human decision). It is the riskiest file here — it owns its
own calendar logic and has already had a selection-reset bug fixed once (TASK-096) — so it
gets its own phase and its own tests, never bundled with a bulk sweep.

## 3. Do not start from scratch

`FilterSheet.vue` (144 lines, TASK-085) is already a bottom sheet holding one single-select
list, built for exactly this reason: a list that could not be read on a phone. The pattern
is proven here. `AppSelect` should reuse its mechanics rather than invent a second sheet.

## 4. The component

`design-system/components/AppSelect.vue` — a themed trigger plus a list we render.

- **Phone:** bottom sheet (full width, comfortable row height).
- **Wider viewport:** popover anchored under the trigger.
- Drop-in for the existing usage shape: `v-model`, an options list, a placeholder, and a
  `disabled` state. Keep the call sites boring so 21 conversions stay mechanical.

**Accessibility is not optional.** A custom select that a keyboard cannot drive is worse
than the native one it replaced, not better:

- `role="listbox"` / `role="option"` / `aria-selected` / `aria-expanded`
- arrow keys move, Enter/Space selects, Esc closes, focus returns to the trigger
- type-ahead to jump to an option
- the trigger keeps the 44px minimum tap target (TASK-087)

Verify it under a **dark tenant theme** — that is the case that motivated the task, and the
one a default-styled list gets wrong.

## 5. Phases

| | Work | Ends with |
|---|---|---|
| **1** | Build `AppSelect.vue` + tests (keyboard, a11y, dark theme, long lists) | component exists, nothing wired |
| **2** | Convert **`ClientsView.vue` only** (5) | the human looks at it on a real phone before the rest |
| **3** | The remaining 10 in the other 6 files | native `<select>` gone except the date input |
| **4** | `BuddhistDateInput.vue` (6), separately, with its own regression tests | zero native selects in the Agent Portal |

Stop after phase 2 and hand back for a look. Rolling 21 conversions before anyone has seen
one is how a small visual disagreement becomes 21 re-edits.

## 6. Acceptance

- [ ] No native `<select>` left in `frontend/src` (checked by grep, phase 4)
- [ ] Keyboard-only operation works on every converted control
- [ ] Renders correctly under a dark tenant theme — trigger AND list
- [ ] `THAILAND_PROVINCES` (77 options) is usable: the list scrolls inside the sheet and
      type-ahead reaches the end
- [ ] Every existing form still submits the same payload — conversions are presentation
      only, no validation or v-model semantics changed
- [ ] `vue-tsc`, `eslint src`, `vite build` clean; vitest green with new tests kept
