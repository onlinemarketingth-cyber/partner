# TASK-200 — remember the last-used effective_from/effective_to within a session

- **Owner:** ag-ui (frontend-admin only)
- **Date:** 2026-08-17
- **Goal:** human pointed out that adding several cert-tier rules back-to-back for the same
  product (via "+ เพิ่มอัตราคอมตาม tier") makes them re-confirm "มีผลตั้งแต่"/"วันหมดอายุ" on every
  single add, even when every tier is meant to start on the same date. Investigated whether dates
  should hoist to a per-product setting the way TASK-197 hoisted `rate_type` — **concluded no**:
  unlike rate format, `effective_from`/`effective_to` are genuinely independent per cert tier at
  the data layer (`CommissionRuleService::assertNoOverlap()` scopes uniqueness to
  `(company_id, cert_tier_id, product_id, product_category_id)` — each tier has its own
  non-overlapping date-range history, by design, so a Basic-tier rate change can take effect on a
  different date than a High-tier one without touching the other). Human confirmed the actual fix
  (see chat, 2026-08-17): keep dates fully independent per rule, but stop resetting the form's date
  fields back to "today"/blank after every successful add — carry the last-entered values forward
  within the session as the new default, still freely editable per rule.
- **Related:** BR-2 (tiered commission — unaffected, no schema/resolution change), TASK-197 (the
  rate_type hoist this is explicitly NOT repeating for dates, for the reason above).

## What does NOT change

- No backend change. `effective_from`/`effective_to` stay exactly what they are today: two fields
  on each individual `commission_rules` row, independently overlap-checked per
  `(cert_tier_id, product_id, product_category_id)`.
- No new column, no "apply to all tiers" button, no product-level date setting.
- A rule's date fields remain fully editable on every single add/edit — this task only changes
  what value the field STARTS at when the form opens/resets, not what it's allowed to contain.

## Fix (frontend only)

**`ProductEditView.vue`'s create-rule form (`ruleForm`):**
Today, `resetRuleForm()` (called after a successful `submitRule()`, and implicitly whenever the
form's initial state is used) resets `effective_from` to `new Date().toISOString().slice(0, 10)`
(today) and `effective_to` to `''` (blank) every time. Change this so a successful submit's reset
preserves whatever `effective_from`/`effective_to` were just used — i.e. split the "clear cert
tier + rate value + renewal fields" behavior from the "reset dates to today/blank" behavior, and
stop doing the latter on a normal post-submit reset. The very first time the page/tab loads, the
fields should still default the way they do today (today's date / blank) — this is purely about
NOT reverting back to that default after the admin has already changed it once in this session.

**`CommissionPlansView.vue`'s rule-creation form**, product-scope path only (category/company-wide
scope's own independent date behavior is unaffected — not part of this request): same change,
find the equivalent form-reset function and apply the identical "don't reset dates on a normal
post-submit reset" behavior.

Do not add any cross-tab or cross-page persistence (no localStorage, no backend round-trip) — this
is in-memory, per-page-load state only, exactly matching how the rest of this page's form state
already behaves.

## Acceptance Criteria

- Open the create-rule form, change the expiry date, add a tier rule, then add a second tier rule
  in the same session without touching the date fields — the second rule submits with the SAME
  dates the first one used, not reset back to today/blank.
- Editing dates on one add doesn't retroactively change any already-created rule (only affects the
  form's own next default).
- Reloading the page resets back to today's date / blank expiry as the starting default, same as
  today.
- `vue-tsc --noEmit` and `eslint src` clean.
