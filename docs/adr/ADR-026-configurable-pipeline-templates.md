# ADR-026 — Configurable Pipeline Templates (per product / category / company)

- **Status:** Accepted
- **Date:** 2026-08-08
- **Amends:** CLAUDE.md §4.3 (Pipeline State Machine), ADR-017 §Decision 2 (order → pipeline coupling)
- **Related:** BR-4 (commission ledger), BR-6 (tenant isolation), BR-7 (no hardcoded business values), TASK-028 (commission rate scoping — the resolution-order precedent)
- **Human decision:** KreangYot, 2026-08-08 — *"แก้เรื่องนี้ได้เลยครับ เพราะเรามีสินค้าที่หลากหลายขึ้น"*

---

## 1. Context

CLAUDE.md §4.3 fixed **one** pipeline for the entire platform:

```
Complete Registered → Waiting Appointment → Finish 1st Doctor Meeting
    → Complete Payment → Ongoing Next Meeting (2nd → 3rd → 4th)
```

That sequence is encoded in three places today:

| Where | What it does | File |
|---|---|---|
| `PipelineStage::allowedNextStages()` | the only legal edges | `app/Enums/PipelineStage.php:37-44` |
| `PipelineService::advance()` | takes **no** target stage — there is only ever one | `app/Services/Referral/PipelineService.php:41-43` |
| `OrderService::confirmPayment()` | hard-refuses unless the referral is at `Finish1stDoctorMeeting` | `app/Services/Order/OrderService.php:128-138` |

The third one is what surfaced the problem. It means **no customer can ever complete a
payment on their own**: a referral created from a public link enters at
`CompleteRegistered`, and an authenticated agent must manually push it through two
medical stages before money can be confirmed — even for a product that has no medical
component at all.

The platform started as a single line of annual health packages sold through Thai Life.
It is no longer that. The catalogue now spans products where the doctor meeting is the
core of the service and products where it is irrelevant. A single hardcoded journey is
therefore a **BR-7 violation that predates BR-7 being taken seriously**: the sequence of
stages is a business value, and business values belong in config.

### What we are NOT trying to fix

This ADR does not make payment self-completing. Removing the medical precondition is
necessary for a customer-pays-from-a-shared-link flow, but not sufficient — that flow
needs a public order-creation endpoint and a verification path, both out of scope here
and specified separately (TASK-134 onward).

---

## 2. Options considered

### Option A — a boolean on the product (`requires_medical_journey`)

One column, one migration, one branch in `PipelineService`.

- **Pro:** could ship in days; impossible to misconfigure into an invalid state.
- **Con:** encodes exactly two journeys forever. The third product shape (pay first, then
  ship, then follow up) needs another boolean, then another. This is the shape that got
  us here.

### Option B — a configurable, ordered template, resolvable at three levels

A named template is an **ordered subset of the `PipelineStage` vocabulary**. Products,
categories and companies each may point at one; the most specific wins.

- **Pro:** admin-editable (BR-7 satisfied properly); one mechanism covers every journey
  shape the human named; matches the resolution model the team already knows from
  commission rules (TASK-028).
- **Con:** materially more work — new tables, template resolution, template-aware
  transition validation, a Kanban board whose columns are no longer fixed, and an
  admin authoring UI.

### Option C — free-text stages defined entirely by the admin

Maximum flexibility: an admin types whatever stage names they want.

- **Pro:** no code change ever needed for a new journey.
- **Con:** rejected. `pipeline_stage_logs.from_stage`/`to_stage` are enum-cast columns —
  free text breaks the audit trail's type safety (BR-6 §Audit Log). Worse, nothing would
  stop an admin from building a journey with **no payment stage**, which silently kills
  BR-4 commission for every product using it. Business vocabulary that other invariants
  depend on must not be user-typed.

**Chosen: Option B**, per the human's answer (all three scoping levels requested, and
both a "register → pay, done" journey and a "register → pay → post-sale steps" journey).

---

## 3. Decision

### 3.1 §4.3 becomes the default template, not the law

The five-stage sequence is preserved verbatim as the seeded template
**`medical_package_default`**, and remains the correct journey for every product that
genuinely involves a doctor meeting. It stops being the only one.

### 3.2 Stages stay a closed enum; templates choose from it

`PipelineStage` remains the single source of stage vocabulary. A template is an ordered
list of enum cases, not a list of strings. Adding a genuinely new stage type (e.g.
delivery, installation) is a code change plus a follow-up ADR — deliberately, for the
three reasons in Option C above.

### 3.3 Resolution order — most specific wins

```
product.pipeline_template_id
  ?? product.category.pipeline_template_id
  ?? company.default_pipeline_template_id
  ?? medical_package_default   (fail-safe; never null in practice)
```

Identical in shape to `CommissionRule` scope resolution (TASK-028), so there is one
resolution idea in this codebase, not two.

### 3.4 The template is snapshotted onto the referral

`referrals.pipeline_template_id` is stamped **once, at referral creation**, and never
re-resolved afterwards.

This is the same reasoning as BR-4's immutable ledger. An admin editing a template must
not silently reroute — or strand — a customer already halfway through a journey. A
referral sitting at `Waiting Appointment` when that stage is removed from its template
would otherwise have no legal next stage and no legal previous one.

### 3.5 Every template MUST contain `complete_payment`

Enforced in the Form Request **and** re-checked in the Service (never trust the client —
§6). BR-4 is untouched by this ADR: commission still fires at `Complete Payment` and
nowhere else. A template without it would be a silent commission outage, so it is not
representable.

`complete_registered` is likewise mandatory as the entry stage.

### 3.6 Transition validation moves from the enum to the template

`PipelineStage::allowedNextStages()` is retained but demoted to
`defaultAllowedNextStages()` — the fallback for rows with no snapshot (pre-migration
referrals). `PipelineService::advance()` asks the referral's template for the next stage.
The rules it enforces are unchanged in spirit: forward-only, no skipping, one legal edge
at a time, every move audit-logged.

`OngoingNextMeeting`'s self-loop (`referrals.meeting_number`) is preserved as a property
of that stage, not of the template.

### 3.7 `OrderService::confirmPayment` precondition is generalised

Replaces the hardcoded `Finish1stDoctorMeeting` check with:

> the referral's **next stage under its own template** is `complete_payment`, or it is
> already at/past it.

For a `medical_package_default` referral this evaluates to exactly today's behaviour —
the medical gate is not weakened for products that still have one. For a
`register → pay` template it is satisfied immediately, which is the whole point.

### 3.8 Existing data

Human decision, 2026-08-08: **existing products do not require a doctor meeting.**

| Rows | Treatment | Why |
|---|---|---|
| Existing `products` (incl. the 8,900 / 9,900 packages) | backfilled to the short template `direct_sale_default` | the human's explicit answer |
| Existing `referrals` **already in flight** | snapshotted to `medical_package_default` (the journey they actually started) | a referral parked at `Waiting Appointment` must keep a stage its template contains — §3.4 |
| New column default | **no DB-level default** on `products.pipeline_template_id`; resolution falls through to the category/company chain | a hardcoded default column value is exactly the BR-7 smell this ADR exists to remove |

This split is the only combination that honours the decision without stranding live
referrals, and is called out here because it is the single most surprising line in the
migration.

### 3.9 Payment confirmation — slip **and** gateway

Human answer: both. Slip verification (built, TASK-054) stays as the always-available
path and gains the two things it is missing — a notification to the agent when a slip
lands, and a Company-Admin verification queue in `frontend-admin` (there is currently no
orders screen there at all). A real gateway with webhook auto-confirmation is additive,
reuses the dormant `orders.payment_reference` column, and is specified separately.

---

## 4. Consequences

### Accepted costs

- **Kanban board columns become dynamic.** `ReferralsView`'s pipeline board currently
  renders five fixed columns; it must render the template's stages. Referrals of
  different templates cannot share one board — the board is filtered per template, or
  grouped.
- **`allowedNextStages()` call sites must all be found.** Any code reading the enum's
  edges directly is now reading the fallback, not the truth.
- **A third resolution chain to keep consistent.** Commission scope, plan type, and now
  pipeline template each resolve product → category → company. If a fourth appears,
  extract the pattern.
- **Reporting across templates gets harder.** "How many referrals are at stage X" is no
  longer comparable across products with different journeys.

### What does not change

- BR-4: commission is written once, at `Complete Payment`, from the ledger, immutably.
- BR-6: templates are `company_id`-scoped like everything else; a product may not point
  at another company's template (validated, not assumed).
- §6 Audit Log: every stage change still writes `pipeline_stage_logs`.
- BR-3: no money handling is touched by this ADR.

---

## 5. Open questions

### RESOLVED — human, 2026-08-08

**Q1. Post-sale stages → all three, added to the enum.**

| Enum case | Key | Thai label |
|---|---|---|
| `Delivery` | `delivery` | จัดส่ง |
| `ServiceAppointment` | `service_appointment` | นัดใช้บริการ |
| `FollowUp` | `follow_up` | ติดตามผล |

Constraints, recorded so they are not re-litigated per template:

- Optional and unordered *as a group* — any subset, any order, each at most once.
- All must sit **after** `complete_payment`. A post-sale step before the sale closes is
  not representable; validated alongside the §3.5 invariant.
- **None of them triggers commission.** BR-4 remains "Complete Payment, once, from the
  ledger". They earn the ordinary per-stage XP (BR-5 source (b)), no bonus — the
  `PaymentComplete` bonus stays exclusive to `complete_payment`.
- They are enum cases, not admin-typed strings — §3.2 and Option C still hold. Three
  more cases is a code change we chose to make once; it does not open the door to
  free-text stages.

**Q2. Payment gateway → Omise (operating as Opn Payments).**

Chosen for three reasons that matter here: it is Thailand-native, its official
`omise/omise-php` library removes the need to hand-roll an HTTP client, and it supports
**PromptPay** — which is what the manual flow already uses, so the customer's experience
does not change shape, only its confirmation stops being a human squinting at a slip.

Non-negotiable for the implementation (TASK-139):

- **Never trust the webhook body.** Take only the charge id from it, then re-fetch that
  charge from the Omise API and act on the re-fetched status. A webhook endpoint is a
  public unauthenticated POST; treating its payload as truth would let anyone mark any
  order paid. ag-dev must verify the current recommended verification mechanism against
  live Omise docs before implementing (Guardrail 3 — do not assume the API shape from
  memory).
- Webhook handling must be **idempotent** — Omise may deliver the same event more than
  once, and `confirmPayment()` already fires commission. Reuse its existing early-return
  on already-`paid` rather than adding a second guard.
- Keys live in `.env` only (§6 Secrets). Never committed, never in a config table.
- Slip verification is **not** removed. It stays as the fallback for customers who
  transfer manually and for companies that do not enable a gateway.

### STILL OPEN — BR-7, not to be guessed

1. **Full payment vs deposit for self-serve customers.** `orders` has no concept of a
   deposit, and BR-4 would need to state which payment triggers commission.
2. **Who owns the verification queue** when a customer pays through an agent's link but
   that agent is inactive.
3. **Duplicate-submit window** for the public checkout endpoint (TASK-136).
