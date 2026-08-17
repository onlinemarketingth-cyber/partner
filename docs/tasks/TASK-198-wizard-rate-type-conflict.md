# TASK-198 — Setup Wizard vs. locked per-product commission_rate_type

- **Owner:** ag-ui
- **Date:** 2026-08-17
- **Goal:** `CommissionPlansView.vue`'s Setup Wizard (`wizardSaveRates()`) hardcodes `rate_type:
  'percentage'` by original design ("wizard only offers % rates, use กฎคอมมิชชั่น for fixed THB").
  Since TASK-197, if the target product's `commission_rate_type` is already locked to
  `fixed_satang` by another form, the wizard's submission now hits the backend's TASK-197 §2.2
  enforcement and gets rejected (422, Thai message) — not silent data corruption, but a confusing
  dead end: the user filled out the whole wizard and only finds out at the last step that this
  product can't use it. Human confirmed: fix this now (see chat, 2026-08-17).
- **Related:** TASK-197 (the enforcement this collides with), BR-2 (tiered commission — unaffected).

## Fix

In `CommissionPlansView.vue`'s wizard flow: as soon as the wizard's target product is known
(product picked, likely the wizard's first step), check that product's `commission_rate_type`.

- If null (no rules yet) or already `percentage` — wizard behaves exactly as today, no change.
- If locked to `fixed_satang` — the wizard cannot proceed for this product. Do not let the user
  fill out the rest of the flow and discover this at submit time. Show a clear inline message at
  the point the product is selected/known (not a submit-time error): this product uses fixed-THB
  commission rates, and direct them to the "+ เพิ่มอัตราคอมตาม tier" flow (the regular product-scope
  form from TASK-197) instead, which already supports both types. Disable/skip the wizard's
  remaining rate-entry step for that product rather than letting the form be submitted only to
  fail.

Keep this minimal — no new backend work, no change to the wizard's behavior for percentage-only
products (the overwhelming majority case today, per the wizard's own original design comment).

## Acceptance Criteria

- Selecting a product already locked to `fixed_satang` in the wizard surfaces the redirect message
  immediately, before the user invests time filling out rate values.
- No 422 is ever reached through the wizard's normal flow anymore for this reason.
- Percentage-locked or unconfigured products: wizard unchanged, zero regression.
- `vue-tsc --noEmit` and `eslint src` clean.

## Out of scope

Making the wizard itself support entering fixed-THB rates (that would be a bigger UX redesign of
the wizard, not asked for — redirecting to the existing full form is sufficient).
