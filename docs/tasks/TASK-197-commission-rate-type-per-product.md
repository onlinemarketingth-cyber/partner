# TASK-197 — hoist commission rate_type to a per-product setting

- **Owner:** ag-lead (spec) → ag-dev (backend) → ag-ui (frontend-admin) → ag-qa
- **Date:** 2026-08-17
- **Human:** found it repetitive/confusing that every time they click "+ เพิ่มอัตรา" to add another
  cert-tier's commission rate for the SAME product, the form asks them to re-pick "% ของยอดขาย" vs
  "จำนวนคงที่ (บาท)" again — a choice that in practice is always the same for one product (a product
  doesn't sensibly pay Basic tier as % and High tier as a flat amount). Confirmed via
  clarifying questions: (1) keep the "+ เพิ่มอัตรา" button — rename it to make clear it adds a rule
  PER CERT TIER, not a second "format"; (2) hoist rate_type to a real per-product setting; (3) the
  existing "รูปแบบค่าคอมหัวหน้าทีม (Affiliate)" (additive/deductive, TASK-194) selector is the
  reference pattern this should follow — group both as one "settings picked once" block, separate
  from the per-tier rate LIST; (4) `ProductCatalogView.vue`'s "ใช้อัตรานี้กับทุกแพ็กเกจ" bulk-apply
  button gets disabled rather than reconciled, since one entered number can't mean the same thing
  across products with different rate_types.
- **Related:** BR-2 (tiered commission — rate depends on cert tier × product; UNCHANGED, this task
  only touches the % vs fixed-amount FORMAT choice, not per-tier VALUES), BR-7 (config not
  hardcoded), TASK-611 (added the rate_type toggle to ProductEditView/CommissionPlansView being
  partially undone here — the per-rule toggle goes away for product-scoped rules), TASK-194/195/196
  (the `products.commission_plan_type`/`affiliate_override_mode` column-naming precedent, and the
  "settings block" grouping precedent in ProductEditView's Commission tab).

---

## 1. What does NOT change

- **BR-2 is untouched.** Each cert tier still gets its own rate VALUE for a product (Basic 5%,
  Intermediate 7%, High 10%, etc.) — the "+ เพิ่มอัตรา" button and the per-tier rule list stay
  exactly as-is in that respect. Only the FORMAT (% vs fixed THB) becomes a single per-product
  choice instead of a per-rule one.
- **Category-scoped and company-wide rules (`product_id` null` in `CommissionPlansView.vue`'s
  scope selector) keep their own independent, freely-chosen `rate_type` per rule, completely
  unchanged.** There is no single product to hoist a format onto for those — this hoist ONLY
  applies to product-scoped rules.
- **Historical `commission_rules` rows are never rewritten.** A product's existing rules keep
  whatever `rate_type` they were created with, even after this task ships and even if it differs
  from the product's newly-configured setting. No backfill/migration of old rows — this is a
  going-forward change to how NEW rules are created/edited, not a data cleanup.

## 2. Backend (ag-dev)

**2.1** New column `products.commission_rate_type` — nullable string, cast to the EXISTING
`App\Enums\CommissionRateType` enum (`Percentage`/`FixedSatang` — do not create a second enum),
`->after('affiliate_override_mode')`. Same nullable/no-default pattern as `commission_plan_type`
and `affiliate_override_mode` (null = "not yet configured for this product" — the frontend defaults
new product-scoped rule forms to `percentage` when null, same as today's per-rule default).
Migration, `Product.php` fillable/casts, `ProductResource.php` exposure, and both
`StoreProductRequest`/`UpdateProductRequest` validation — the same four-file pattern those two
prior columns followed.

**2.2 Server-side enforcement.** When a `commission_rules` row being created/updated has a non-null
`product_id`, its `rate_type` must match that product's `commission_rate_type` — reject (422,
clear Thai message) a mismatch. This is the authoritative-config enforcement pattern TASK-196 just
established for the rate cap (don't trust the frontend alone). Two edge cases to get right:
  - If the product's `commission_rate_type` is still null (never configured), accept the incoming
    `rate_type` as given AND set the product's `commission_rate_type` to that value as a side
    effect of this first rule creation (the "first rule for this product decides the format"
    behavior the frontend UX in §3 relies on) — do this in the same transaction as the rule write.
  - Category-scoped and company-wide rules (`product_id` null) are completely exempt from this
    check — their `rate_type` stays freely chosen, no product to validate against.

**2.3 `ProductCatalogView.vue`'s bulk-apply endpoint** (whatever `applyRateToAllProducts()` calls —
find the real route) — confirm whether it's a distinct endpoint or just N calls to the same
`POST/PUT /commission-rules`. If it's N calls to the same endpoint, §2.2's per-product validation
already makes a mismatched bulk-apply fail per-product naturally; if it's its own bespoke endpoint,
it needs the same product-type-matching logic, OR (simpler, and consistent with the human's
decision in §0) it can be removed/disabled entirely per §3.3 — ag-dev's call on which is less
invasive, but the END STATE per the human's decision is: this bulk button must not let one entered
number silently mean different things for different products.

**2.4 Feature tests.** A product's first rule sets `commission_rate_type` automatically. A second
rule for the same product with a different `rate_type` is rejected (422). A category-scoped and a
company-wide rule can each freely use any `rate_type` regardless of any product's own setting.
Existing (pre-task) rows with mixed `rate_type` for one product are left untouched by a migration
dry-run check (no data mutation happens on deploy). Regression: the full existing
`CommissionRuleTest`/`CommissionRuleRateCapTest` (TASK-196) suites still pass unchanged.

## 3. Frontend (ag-ui, frontend-admin only)

**3.1 Rename the button.** "+ เพิ่มอัตรา" → "+ เพิ่มอัตราคอมตาม tier" (or ag-ui's closest fit if a
button's width/context needs shorter wording — meaning must stay "adds a rate FOR A CERT TIER").
Apply everywhere this exact button exists across the 3 forms (`ProductEditView.vue`,
`CommissionPlansView.vue`'s product-scope path, `ProductCatalogView.vue` if it has its own).

**3.2 One settings block per product, grouped with the Affiliate override selector.** In
`ProductEditView.vue`'s Commission tab (Tab 2, post-TASK-195): create a single small
"การตั้งค่าคอมมิชชั่นของสินค้านี้" block near the top of the tab, ABOVE the per-tier rate list —
containing (a) the "รูปแบบอัตราคอมมิชชั่น" (%/fixed) selector, now bound to
`basicsForm.commission_rate_type` (product-level, saved via the same `saveBasics()` call the
Affiliate field already uses — do not invent a second save path), and (b) the existing "รูปแบบ
ค่าคอมหัวหน้าทีม (Affiliate)" selector relocated into this same block (still `v-if
="isEffectivelyAffiliate"` — unrelated products don't show it). This directly answers the human's
point 3: both are "set once per product" settings, now visually grouped as such, distinct from the
"+ เพิ่มอัตราคอมตาม tier" list below which is genuinely per-tier repeatable data.

**3.3 Remove the per-rule rate_type selector** from the "+ เพิ่มอัตราคอมตาม tier" add-form and the
inline per-rule edit form in BOTH `ProductEditView.vue` and `CommissionPlansView.vue` (product-scope
path only — the category/company-wide scope path in `CommissionPlansView.vue` KEEPS its own
rate_type selector, untouched, per §1). The add/edit forms now just submit whatever the resolved
product-level `commission_rate_type` is (read-only display of "จะบันทึกเป็น: {type}" next to the
rate-value input is a reasonable touch so the admin isn't guessing which unit their number means,
ag-ui's call on exact wording).

**3.4 `CommissionPlansView.vue`'s product-scope path** additionally needs: when a product is
selected in the add-rule form, look up (or lazily fetch) that product's current
`commission_rate_type` to know which unit to submit/display — if the product has none configured
yet (null), the rate_type selector for THIS specific first-rule-for-this-product case still needs
to appear once (since nothing exists yet to inherit) — after that first submission, subsequent
"+เพิ่มอัตราคอมตาม tier" clicks for the same product no longer show it (per §2.2's backend behavior
of auto-setting it on first use).

**3.5 `ProductCatalogView.vue`.** Per the human's decision: remove/disable the "ใช้อัตรานี้กับทุก
แพ็กเกจ" bulk-apply checkbox and its associated `applyRateToAllProducts()` code path entirely — keep
only the per-product form. That per-product form currently hardcodes `rate_type: 'percentage'`
(TASK-196's own comment confirms this) — update it to read and respect the selected product's
`commission_rate_type` the same way ProductEditView/CommissionPlansView now do (§3.3/§3.4's pattern),
rather than leaving it silently percentage-only forever.

**3.6 Verification.** `vue-tsc --noEmit`, `eslint src`. Live click-through: add a first rate rule
for a fresh product (rate_type selector appears once), add a second rule for a different cert tier
on the SAME product (rate_type selector is gone, submits using the now-locked-in type
automatically), confirm the "การตั้งค่าคอมมิชชั่นของสินค้านี้" block shows the resolved type and
the Affiliate mode together. Confirm the bulk-apply button/checkbox no longer exists on
ProductCatalogView.

## 4. Definition of Done

CLAUDE.md §9, plus: BR-2's per-tier rate values are unaffected, category/company-wide rules keep
free rate_type choice, no historical row is rewritten, server-side enforcement exists independently
of the frontend (a direct API call with a mismatched rate_type is still rejected), and the bulk
"apply to all products" ambiguity is fully closed (not left half-working).
