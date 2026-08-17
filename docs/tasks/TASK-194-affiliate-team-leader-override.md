# TASK-194 — team-leader commission override for Affiliate-plan products

- **Owner:** ag-lead (spec) → ag-dev (backend) → ag-ui (frontend-admin) → ag-qa
- **Date:** 2026-08-17
- **Human:** asked how to set up an Affiliate plan that pays % or a flat amount (both already exist,
  see investigation below) AND splits commission to the team leader (did NOT exist — confirmed by
  reading `CommissionService::recordForReferral()`, which branches on plan type into 5 mechanisms
  and has no `Affiliate` case at all). Asked to build it. Clarified via two rounds of questions:
  1st round confirmed the gap is real, not a config mistake. 2nd round (AskUserQuestion) offered
  "additive, mirrors Unilevel" vs "deductive, split from the agent's own commission" as the two
  possible payout maths — **human chose both, selectable per product from the product edit page.**
- **Related:** BR-2, BR-3 (integer satang), BR-4 (immutable ledger, "one door"), TASK-025 (existing
  `manager_id` + `CommissionOverrideRule` + Unilevel override — reused, NOT modified), TASK-032/033
  (Affiliate links/attribution — unaffected, this task only touches the money side).

---

## 1. What already exists (no work needed here)

- **Percentage or flat-amount base commission for an Affiliate product**: already works via the
  existing "อัตราคอมมิชชั่น" (Commission Rules) tab — `commission_rules.rate_type` (`percentage` /
  `fixed_satang`) applies to every plan type identically, Affiliate included. Nothing to build.
- **`manager_id` assignment** (`AgentEditModal.vue`, "หัวหน้า (Upline)") and **`CommissionOverrideRule`**
  configuration (`ProductCatalogView.vue`, "ค่าคอมหัวหน้าทีม (Override)" tab — rate keyed by the
  MANAGER's own cert tier, company-wide, not tied to a product) both already exist and are reused
  as-is by this task. **Do not duplicate this table or build a second rate-configuration screen.**

## 2. The gap this task closes

`CommissionService::recordForReferral()` has an `if/elseif` chain keyed on the SOLD product's
`effectivePlanType()` that invokes exactly one of 5 mutually-exclusive payout mechanisms
(Unilevel override / Binary / Matrix / Stairstep / Generation). There is no `Affiliate` branch, so
a sale on an Affiliate-plan product never pays the selling agent's manager anything, regardless of
`manager_id` or `CommissionOverrideRule` configuration.

## 3. New behavior — two payout modes, chosen per product

**3.1 Schema (ag-dev).** Add `affiliate_override_mode` to `products`: nullable string/enum,
values `'additive'` | `'deductive'`, default `null`. `null` is treated as `'additive'` at
calculation time (safe default — matches the already-familiar Unilevel behavior, and a product
with no manager assigned to its sellers or no matching `CommissionOverrideRule` produces zero
override payout either way, so this column has no effect until an admin deliberately wires both
manager_id AND an override rule — same "nothing changes until explicitly configured" safety
property TASK-025's own override already has).

Add a small enum, e.g. `App\Enums\AffiliateOverrideMode` (`Additive`, `Deductive`), rather than a
bare string column — matches this codebase's convention (`CommissionPlanType`, `CommissionRateType`
are both enums, not strings) and gives ag-ui a single source of valid values.

**3.2 The two maths — spelled out precisely, this is money (BR-3), don't improvise rounding:**

Let `agentAmount` = the selling agent's own commission for this sale, exactly as
`CommissionService` already computes it today from `commission_rules` (percentage or fixed_satang,
unchanged). Let `overrideRate` = the `CommissionOverrideRule.rate_value` matching the manager's own
passed cert tier (unchanged lookup — same as Unilevel's `recordOverrides()` already does; if no
manager, or manager has no passed cert tier, or no matching rule exists, there is **no override
payout in either mode** — same fail-safe as Unilevel).

- **Additive** (mirrors Unilevel exactly, byte-for-byte reuse of `recordOverrides()`'s own existing
  math — do not reimplement, call the same private method/logic): manager's payout =
  `overrideRate% × productPriceSatang` (same base Unilevel's override already uses — the product's
  price, not the agent's commission). Agent's own `agentAmount` is **untouched**. Total commission
  paid out for this sale increases by the manager's payout — same as today's Unilevel behavior.

- **Deductive** (new): manager's payout = `round(overrideRate% × agentAmount)`, computed in integer
  satang (BR-3 — no floats). Agent's own commission ledger entry for this sale is then recorded as
  `agentAmount − managerPayout` (not the original `agentAmount`) — **the two ledger rows must sum
  to exactly the original `agentAmount`, no more, no less.** Round the manager's cut first, then
  subtract from the agent's amount to get the agent's row — never round both sides independently
  (that can lose or gain a satang and break the sum invariant). Total commission cost to the
  company for this sale is UNCHANGED from what a plain Affiliate sale with no manager would have
  cost — it's a genuine split of one pool, not an addition.

**3.3 Ledger.** Both modes write TWO `commission_ledger` rows per sale (agent + manager), same as
Unilevel's override already does — reuse the existing row-shape/fields, just change which amount
goes on the agent's own row in deductive mode. BR-4 (immutable, one door, at Complete Payment only)
is otherwise unaffected — this is still one `recordForReferral()` call, still inside the same
transaction, still guarded by the same `!$alreadyClosed` idempotency check.

**3.4 Where the mode is set (ag-ui).** `ProductEditView.vue`'s commission section, next to the
existing per-product `commission_plan_type` override field (~line 1572) — add a new selector,
**only rendered when the product's effective plan type is `Affiliate`** (match whatever conditional
pattern already governs other plan-type-specific fields on this page, if any exist; if none do,
gate on `effectivePlanType === 'affiliate'` computed from the same value the plan-type select
already reads). Two options, Thai labels (ag-ui may refine wording, keep the meaning exact):
`จ่ายเพิ่มแยกต่างหาก (Additive)` / `หักจากค่าคอมตัวแทน (Deductive)`. Default/placeholder shows
"Additive" when the underlying value is null, consistent with §3.1.

## 4. Verification

- Feature tests, both modes: a referral on an Affiliate-plan product, agent has a manager, manager
  passed a cert tier with a matching `CommissionOverrideRule`. Assert exact satang amounts for
  BOTH ledger rows in additive mode (agent's row = full `agentAmount`, unchanged; manager's row =
  `overrideRate% × price`) and in deductive mode (rows sum to exactly `agentAmount`, manager's row
  computed first then subtracted, no rounding drift across 3+ differently-priced test cases picked
  to force non-exact-satang percentages).
- No manager assigned → single ledger row only, in both modes (fail-safe unchanged).
- No `CommissionOverrideRule` for the manager's cert tier → single ledger row only, in both modes.
- Regression: Unilevel, Binary, Matrix, StairstepBreakaway, Generation referrals are byte-identical
  in ledger output before/after this change — the new branch must not alter the existing `if/elseif`
  chain's other arms. Run the FULL existing commission test suite, not just new tests.
- `affiliate_override_mode` defaults to null on existing products (no migration data-fill needed —
  null already means "additive" at read time) and a product with the field unset behaves exactly as
  Affiliate did before this task (i.e., still nothing paid to a manager unless BOTH manager_id and
  an override rule are separately configured) — confirms no surprise change to already-live orders.
- `pint`, `vue-tsc --noEmit`, `eslint src` (frontend-admin only).

## 5. Definition of Done

CLAUDE.md §9, plus: reuses the existing `CommissionOverrideRule`/`manager_id` infrastructure with
no duplicate rate-configuration screen, the deductive-mode ledger rows are proven to sum exactly to
the pre-split amount (no satang drift), and every other plan type's existing ledger output is
regression-tested unchanged.
