# TASK-199 — commission-rule expiry date field + พ.ศ. calendar consistency

- **Owner:** ag-ui (frontend-admin only)
- **Date:** 2026-08-17
- **Goal:** human noticed `ProductEditView.vue`'s commission-rule form only shows "มีผลตั้งแต่"
  (effective_from), no expiry date at all, despite the backend already fully supporting
  `effective_to`. Investigation found a second, related inconsistency: `CommissionPlansView.vue`'s
  equivalent rule form DOES have both `effective_from` and `effective_to`, but renders them as
  plain native `<input type="date">` (Gregorian/ค.ศ.) instead of the project-standard
  `BuddhistDateInput.vue` (พ.ศ.) component used everywhere else, including in `ProductEditView.vue`
  itself for `effective_from`. Human confirmed: fix both (see chat, 2026-08-17).
- **Related:** none of BR-1..BR-7 — this is a UI completeness/consistency fix, not a business-rule
  change. `effective_to` already exists end-to-end on the backend (model, validation, resolution
  logic) — nothing here is new backend surface.

## 1. `ProductEditView.vue` — add the missing expiry field

**Create-rule form** (`ruleForm`, around the "+ เพิ่มอัตราคอมตาม tier" form):
- Add `effective_to: '' as string` to the `ruleForm` ref's shape and to `resetRuleForm()`'s reset
  object.
- Add a `BuddhistDateInput v-model="ruleForm.effective_to"` field next to/after the existing
  "มีผลตั้งแต่" field, labeled "วันหมดอายุ (ไม่บังคับ)" — NOT `required` (nullable, matches
  `CommissionPlansView.vue`'s own optional `effective_to`).
- In `submitRule()`'s POST payload, add `effective_to: ruleForm.value.effective_to || null`
  alongside the existing `effective_from`.

**Inline edit-rule form** (`editRuleForm`): the state already has `effective_to` (populated in
`startEditRule()` from `rule.effective_to`) and it's already sent in `saveEditRule()`'s PUT
payload — it is silently preserved today but the human has no way to actually CHANGE it. Just add
the missing `BuddhistDateInput v-model="editRuleForm.effective_to"` field to the template next to
the existing `effective_from` field in the inline edit form, same "วันหมดอายุ (ไม่บังคับ)" label,
not required.

## 2. `CommissionPlansView.vue` — switch native date inputs to `BuddhistDateInput`

Import `BuddhistDateInput` (same path `@/design-system/components/BuddhistDateInput.vue` used in
`ProductEditView.vue`) and replace every plain `<input type="date">` in this file with it, so this
view matches the rest of the system:

- The commission-rule form's `effective_from` and `effective_to` inputs (the ones directly tied to
  the human's screenshot/question — company-wide/category/product-scope rule form).
- `levelRateForm.effective_from` (Matrix tab's per-level rate form).
- `generationRuleForm.effective_from` (Generation tab's per-generation rate form).

Each becomes `<BuddhistDateInput v-model="x.effective_from" required />` (or without `required`
for the optional `effective_to`), same pattern as `ProductEditView.vue` already uses. No `v-model`
binding, submit payload shape, or validation logic changes — this is a display/input-component
swap only, the underlying string value format (`YYYY-MM-DD`) stays identical so nothing else in
either file needs to change.

## Acceptance Criteria

- `ProductEditView.vue`'s create-rule and inline-edit-rule forms both have a working, optional
  "วันหมดอายุ" field using `BuddhistDateInput`, and the value round-trips correctly (create a rule
  with an expiry, reload, edit it, confirm the date shown/edited is right).
- Every date input across both files uses `BuddhistDateInput` — no remaining native
  `<input type="date">` in either file.
- `vue-tsc --noEmit` and `eslint src` clean.
- No backend changes needed or made — verify by NOT touching any file outside
  `frontend-admin/src/views/`.

## Out of scope

Adding `effective_to` to the Matrix/Generation per-level/per-generation forms (those don't have an
`effective_to` field today and weren't part of the human's question — only their `effective_from`
picker gets swapped to `BuddhistDateInput` for calendar consistency, per §2).
