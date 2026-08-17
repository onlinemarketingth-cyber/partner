# TASK-196 — commission-rate cap (config, default 30%) + live blocking validation

- **Owner:** ag-lead (spec) → ag-dev (backend) → ag-ui (frontend-admin) → ag-qa
- **Date:** 2026-08-17
- **Human:** saw a commission rule being entered as a flat 1,000 THB amount with no visibility into
  what % of the sale price that represents, and asked for a guard: no commission rate/amount may
  exceed some % of the product's sale price, enforced live while typing (not only at save), shown
  as a blocking modal. Clarified via AskUserQuestion: the cap itself must be BR-7 config, Super
  Admin-editable — **not hardcoded** — seeded with a default of 30%.
- **Related:** BR-7 (never hardcode a business value), BR-3 (integer satang/basis-points, no
  floats), TASK-190 (the closest existing precedent for a single global Super-Admin-only settings
  row — `platform_mail_settings` — reuse that exact shape/pattern, don't invent a new one).

---

## 1. Scope — deliberately narrow, flag anything wider

**In:** the base commission rate a product pays its selling agent — `commission_rules.rate_type` /
`rate_value`, entered through the THREE existing forms that all write to `/commission-rules`
(confirmed to be 3 separate implementations of the same form, not 1):
`frontend-admin/src/views/ProductEditView.vue`'s "อัตราคอมมิชชั่น" tab (Tab 2 post-TASK-195),
`frontend-admin/src/views/CommissionPlansView.vue`'s "กฎคอมมิชชั่น" tab, and
`frontend-admin/src/views/ProductCatalogView.vue`'s equivalent. All three must get the same cap
check — extract one shared implementation (composable/util), do not write the check 3 times.

**Out, explicitly — do not silently expand into these, flag back to ag-lead if it seems necessary:**
- The renewal rate fields (`renewal_rate_type`/`renewal_rate_value`) on the same forms.
- `CommissionOverrideRule` (team-leader override, TASK-025/194).
- Binary matched rate, Matrix level rates, Stairstep rank rates, Generation rates
  (`CommissionPlansView.vue`'s other 4 tabs) — these are different tables/forms entirely.

The human's request and the screenshot were both about the base per-sale agent rate specifically.
If there's an appetite to extend the cap to the others later, that's a follow-up task with its own
human confirmation on scope (a stairstep rank's rate, for instance, isn't obviously "% of one sale"
in the same way — don't assume it generalizes cleanly).

## 2. Backend (ag-dev)

**2.1 Config storage.** New table `platform_commission_settings` — single global row, no
`company_id` (mirrors `platform_mail_settings`'s exact reasoning from TASK-190: avoids the
null-`company_id`-for-Super-Admin defect class already tracked elsewhere as task #583). Column:
`max_commission_rate_basis_points` (unsigned int, default `3000` = 30.00% — stored the same way
`commission_rules.rate_value` already stores percentages, for direct comparability, BR-3-adjacent
consistency even though this isn't money itself). Seed the default row in the migration itself
(not a separate seeder) so it's never legitimately absent — every environment has a cap from the
moment this migrates, matching "fail closed / always a value" reasoning already used elsewhere in
this app (e.g. `is_enabled` defaults).

**2.2 Ability + endpoint.** `Ability::CommissionRateCapUpdate` (new case, provenance docblock "new,
no prior call site" — same honesty pattern as `Ability::SettingsMailUpdate`). Granted to
`SuperAdmin` only for WRITE. READ (`GET /platform/commission-cap` or similar — ag-dev's naming
call, match this app's existing `/platform/...` prefix convention from TASK-190) must be reachable
by any authenticated Company Admin/Super Admin who can reach the 3 forms above — this is a
read-everywhere, write-Super-Admin-only shape, same as `/cert-tiers`' own "any authenticated user"
read gate (no new Policy needed for reads beyond authentication, per that existing precedent).

**2.3 Server-side enforcement — do not rely on the frontend alone.** Add validation to whichever
Form Request(s) actually handle `POST/PUT /commission-rules` (find the real class names — likely
`StoreCommissionRuleRequest`/`UpdateCommissionRuleRequest` or similar): given `rate_type`,
`rate_value`, and the rule's `product_id` (on create) or the existing rule's own product (on
update), compute the implied commission amount against that product's CURRENT `price_satang` and
reject with a clear Thai validation message if it exceeds the configured cap. This must hold
regardless of `rate_type` — a `fixed_satang` value is just as capable of exceeding the cap as a
`percentage` one, and the human's own screenshot was on the fixed-amount form. Read the cap from
`platform_commission_settings` (a single cached/short-lived read is fine, same
`MailSettingsService::applyRuntimeConfig()`-style caching precedent from TASK-190 if this codebase
already has a pattern for that — check before inventing a new caching approach).

**2.4 Feature tests.** Cap defaults to 30% on a fresh migration with zero seeder calls needed.
Creating/updating a `percentage`-type rule above the cap is rejected (422) with both rates: exactly
at the cap (allowed) and 1 basis point over (rejected) — boundary-tested, not just "clearly over."
Same boundary tests for a `fixed_satang`-type rule computed against a real product price. Read
endpoint reachable by Company Admin; write endpoint rejected (403) for Company Admin, allowed for
Super Admin.

## 3. Frontend (ag-ui, frontend-admin only)

**3.1 One shared implementation.** A composable (e.g. `useCommissionRateCap.ts`) that: loads the
cap once (cached for the session, same pattern this app already uses elsewhere for rarely-changing
config), and exposes a pure function `impliedPercent(rateType, rateValue, priceSatang)` plus
whatever boolean/derived state the 3 forms need to both (a) disable their Save button while the
current in-progress input exceeds the cap, and (b) know when to surface the modal. Import this into
all 3 forms — do not reimplement the math 3 times.

**3.2 Live check, not just on submit.** Recompute on every change to the rate value input AND the
rate-type toggle (switching from % to fixed amount, or vice versa, can flip a previously-valid
value into an invalid one against the same price). Debounce the check against raw keystrokes (e.g.
on `blur` and on a short debounce of `input`, not synchronously on every keypress) so the modal
doesn't fire mid-typing before the user has finished entering a number — but the Save button must
stay disabled the entire time the current value is over the cap, even before the modal has fired.

**3.3 The modal.** Reuse this app's existing modal infrastructure — check `ConfirmDialog.vue`
(already used in 8+ places, TASK-066) for whether it supports a single-button "alert" mode (no
Cancel), or whatever this codebase's closest existing pattern for a blocking informational modal
is; do not build a new modal component for this if an adequate one already exists. Copy, in Thai,
what the specific number means: e.g. "อัตราที่กรอกไว้ ({X}) เกิน {cap}% ของราคาขายสินค้านี้
({price}) กรุณาแก้ไขก่อนบันทึก" — state the actual entered value, the cap, and the product price so
the admin understands why, not just that something's wrong.

**3.4 Verification.** `vue-tsc --noEmit`, `eslint src`. Live check: enter a value across the cap in
each of the 3 forms (both rate types), confirm the modal fires once (not per keystroke) and Save
stays disabled until corrected; confirm a value exactly at the cap is accepted; confirm switching
rate_type on an already-entered value re-evaluates correctly.

## 4. Definition of Done

CLAUDE.md §9, plus: the 30% cap is Super-Admin-editable config (BR-7), never hardcoded in any of
the 3 frontend forms or the backend validator; server-side enforcement exists independently of the
frontend check (a direct API call must also be rejected, not just the UI); one shared
implementation on each side (backend validation rule, frontend composable) — not 3 copies.
