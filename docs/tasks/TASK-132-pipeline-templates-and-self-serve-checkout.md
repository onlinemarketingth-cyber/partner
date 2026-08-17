# Sprint TASK-132 → TASK-140 — Configurable Pipeline + Customer Self-Serve Checkout

- **Author:** ag-lead
- **Date:** 2026-08-08
- **Driver:** ADR-026 (configurable pipeline templates); human request *"เสนอระบบ Frontend ที่ทำให้ลูกค้าที่ได้แชร์ไปสามารถ Payment ได้เลย"*
- **Amends:** CLAUDE.md §4.3 (already updated), ADR-017

## Why this is two halves, not one task

The requested feature — *a customer opens a shared link and pays* — was blocked by one
line of business logic, not by missing screens. `OrderService::confirmPayment` refuses
unless the referral has finished a doctor meeting. The human has authorised changing
that rule (ADR-026), so this sprint is:

- **Half A (TASK-132…135)** — make the pipeline configurable. Nothing customer-facing.
  This is the unblock, and it stands on its own merits regardless of checkout.
- **Half B (TASK-136…140)** — build the customer checkout on top of the unblocked rule.

Half B must not start before Half A's QA gate passes. If Half A slips, Half B has
nothing to stand on.

---

## HALF A — Configurable pipeline

### TASK-132 — Backend: pipeline template schema + resolution

```
Task: pipeline_templates schema, model, resolution chain
Owner: ag-dev
Goal: Make the sequence of pipeline stages config data (BR-7) instead of a hardcoded
      enum edge list, resolvable product → category → company.
Related: ADR-026 §3.1-3.4, CLAUDE.md §4.3 (amended), BR-6, BR-7, TASK-028 (precedent)

Input:
  - Existing App\Enums\PipelineStage (5 cases).
  - Existing resolution precedent: CommissionRule scope resolution (TASK-028).

Expected output:
  - PipelineStage gains 3 post-sale cases (human, 2026-08-08 — ADR-026 §5 Q1):
      Delivery = 'delivery', ServiceAppointment = 'service_appointment',
      FollowUp = 'follow_up'. English labels in label() per §7; Thai labels
      (จัดส่ง / นัดใช้บริการ / ติดตามผล) belong to the UI layer, not the enum.
      These are optional, unordered as a group, each at most once, and may only
      appear AFTER complete_payment in a template — validated with the §3.5 invariant.
      None triggers commission (BR-4 unchanged); they earn ordinary per-stage XP only.
  - Migration: pipeline_templates
      id, company_id (FK cascade), key (string), name (string),
      is_system (bool, default false), timestamps
      unique(company_id, key)
  - Migration: pipeline_template_stages
      id, company_id, pipeline_template_id (FK cascade), stage (string — enum-cast),
      position (uint), timestamps
      unique(pipeline_template_id, stage), index(pipeline_template_id, position)
  - Migration: add pipeline_template_id (nullable FK, nullOnDelete) to
      products, product_categories, companies (as default_pipeline_template_id),
      and referrals (the snapshot — ADR-026 §3.4).
  - Models PipelineTemplate, PipelineTemplateStage with TenantScope + $fillable
      (never $guarded = []).
  - PipelineTemplateResolver service implementing the ADR §3.3 chain.
  - Seeder: two system templates per company —
      medical_package_default  = all 5 stages, current order (§4.3 verbatim)
      direct_sale_default      = complete_registered → complete_payment

Acceptance Criteria:
  - Resolution returns the product's template when set; falls through category →
    company → medical_package_default otherwise. Covered by a test per level.
  - A product may NOT reference another company's template (validated, tested → 422).
  - tenant isolation must pass (cross-tenant access → 403/404)
  - Every template contains complete_registered AND complete_payment — enforced in the
    Service, not only the Request (ADR-026 §3.5). A test asserts the Service rejects it
    even when the Request is bypassed.
  - referrals.pipeline_template_id is stamped at creation and never rewritten. A test
    edits a template after a referral exists and asserts the referral's journey is
    unchanged.

Out of scope: any UI; adding new stage cases to the enum; touching OrderService.
```

### TASK-133 — Backend: template-aware PipelineService + OrderService

```
Task: Move transition validation from the enum onto the template
Owner: ag-dev
Goal: advance() and confirmPayment() obey the referral's own template.
Related: ADR-026 §3.6, §3.7, BR-4, CLAUDE.md §4.3, §6 Audit Log

Input:
  - PipelineStage::allowedNextStages()      (app/Enums/PipelineStage.php:37)
  - PipelineService::advance()              (app/Services/Referral/PipelineService.php:41)
  - OrderService::confirmPayment()          (app/Services/Order/OrderService.php:112-155)

Expected output:
  - allowedNextStages() renamed defaultAllowedNextStages(); every call site found and
    updated (grep the whole backend + both frontends for the string).
  - PipelineService::advance() derives the next stage from the referral's template.
    Forward-only, no skipping, one legal edge — unchanged in spirit.
  - OngoingNextMeeting self-loop + meeting_number behaviour preserved exactly.
  - confirmPayment()'s Finish1stDoctorMeeting check replaced per ADR-026 §3.7.

Acceptance Criteria:
  - A medical_package_default referral behaves BIT-IDENTICALLY to today. This is the
    single most important test in the sprint: a regression here silently changes how
    every existing Thai Life sale works. Assert the full 5-step walk + the 422 message
    when confirming payment too early.
  - A direct_sale_default referral can go complete_registered → complete_payment and
    confirmPayment() succeeds immediately.
  - Commission still fires exactly once, at complete_payment, on both templates (BR-4).
  - Every transition still writes pipeline_stage_logs (who/when/from→to).
  - A referral whose pipeline_template_id is NULL (legacy) falls back to the enum
    default and still works.

Out of scope: UI; gateway; public endpoints.
```

### Decision — `referrals.branch` on a self-serve order (human + ag-lead, 2026-08-08)

The human deferred building real branches ("ยังไม่ตัดตอนนี้ ทำ checkout ก่อน"). But
`referrals.branch` is a **required free-text string** today, and the public checkout has
no agent to type it and no customer who could know it.

**Ruling: make `referrals.branch` nullable; self-serve referrals leave it NULL, and every
UI renders NULL as "ผ่านลิงก์ออนไลน์".**

Not a placeholder string, deliberately. A fake value like `'ONLINE'` or `'-'` is
indistinguishable from a real branch name once it is in the column, so the day branches
become a real entity, every self-serve row would have to be un-guessed by hand. NULL
means "this sale did not happen at a branch", which is simply true, and is one `WHERE`
clause away when the migration to real branches happens.

Existing rows are untouched — this widens the column, it does not rewrite history.

**Standing debt (not scheduled):** `branch` remains free text, so branch-level reporting
is unreliable (four spellings of one branch count as four). Revisit when the human is
ready; see the four options presented 2026-08-08.

### TASK-134a — Data migration (REQUIRED before checkout)

```
Task: Backfill products + referrals to their templates
Owner: ag-dev
Blocks: TASK-136 — until this runs, every existing product still resolves to
        medical_package_default and the public checkout would 422 on all of them.
```

### TASK-134b — Admin template authoring UI (DEFERRED)

Not needed for checkout; the two seeded templates cover both journeys. Schedule after
Half B ships, or sooner if the human wants to build a third journey.

```
Task: Backfill existing rows; template authoring screen
Owner: ag-dev (migration) + ag-ui (screen)
Goal: Apply the human's 2026-08-08 decision to live data, and let an admin manage
      templates without a developer.
Related: ADR-026 §3.8

Expected output:
  - Data migration:
      * every existing product        → direct_sale_default  ("สินค้าเดิมไม่ต้องพบแพทย์")
      * every existing referral       → medical_package_default (the journey it started)
    Both directions reversible in down().
  - frontend-admin: template list + editor (pick stages, order them), plus a template
    selector on ProductEditView and on the category form.

Acceptance Criteria:
  - The migration's two different defaults are asserted by a test with seeded fixtures
    of both kinds — this is the least obvious line in the sprint (ADR-026 §3.8).
  - The editor cannot save a template missing complete_registered or complete_payment;
    the reason is shown in Thai, not a raw 422.
  - System templates (is_system) cannot be deleted, only copied.
  - UI works on Desktop / Tablet / Mobile with loading/empty/error states.

Out of scope: adding new stage types (blocked — see BR-7 question 1).
```

### TASK-135 — Half A QA gate

```
Task: QA — pipeline template regression + isolation
Owner: ag-qa
Related: ADR-026, CLAUDE.md §9

Acceptance Criteria:
  - Regression: replay the full existing pipeline test suite unchanged. Any diff in
    medical-template behaviour is a blocker, not a finding.
  - Cross-tenant: company A's product referencing company B's template → 422/403.
  - IDOR: GET/PUT/DELETE another company's template by guessed id → 403/404.
  - Fail-closed: referral with a template whose stages were emptied by hand in the DB
    → service must refuse, never silently skip to payment.
  - Commission integrity: assert commission_ledger row count is unchanged across the
    whole regression run.
  - Report actually-run results only (Guardrail 4).
```

---

## HALF B — Customer self-serve checkout

Blocked on TASK-135 passing.

### TASK-136 — Backend: public checkout from a share token

```
Task: Let an anonymous visitor turn a product share link into a payable order
Owner: ag-dev
Goal: Close the gap between /p/{token} (view-only) and /pay/{token} (already works).
Related: ADR-019, ADR-017, ADR-026, BR-1, BR-6, PDPA (§6)

Input:
  - Precedent to copy, NOT reinvent: AffiliateLeadCaptureService::capture()
    (app/Services/Referral/AffiliateLeadCaptureService.php:57-113) is the only existing
    code that creates Client + Referral for an anonymous visitor. Follow its shape:
    honeypot, generic response, BR-1 gate on the link's agent, consent_given_at.

Expected output:
  - POST /api/v1/public/product-shares/{token}/checkout   (throttle:10,1)
    body: name, phone, email?, branch, consent (+ hp_field honeypot)
    → in ONE transaction: Client (lead_source = 'Product Share') + Referral
      (stamped with the resolved pipeline_template_id) + Order (pending)
    → returns ONLY { pay_url } — never ids, never the agent, never the client.
  - Refuse (422, generic message) when the resolved template's first payable step is
    not reachable — i.e. the product still requires a medical journey. Those products
    fall back to the existing lead-capture behaviour instead.

Acceptance Criteria:
  - Revoked link → 404. BR-1-failing agent → generic non-committal success, no rows
    written (mirrors AffiliateLeadCaptureService:59).
  - tenant isolation must pass (cross-tenant access → 403/404)
  - The response body is asserted to contain no id, no company_id, no agent name.
  - Duplicate submits (same phone, same link, <N min) do not create a second order —
    reuse the existing pending order. // TODO: CONFIRM (business rule) — N is a BR-7
    value, ask the human before picking one.
  - PDPA: consent_given_at set; health data untouched by this endpoint.

Out of scope: gateway; deposits; changing the pay page.
```

### TASK-137 — Frontend: buy button + checkout sheet on the share page

```
Task: ProductShareView gains a purchase path
Owner: ag-ui
Related: TASK-136, ADR-019

Note: ProductShareView.vue:13-15 currently carries a comment stating view-only was a
      human-confirmed scope call. That comment must be REPLACED (not deleted) with the
      2026-08-08 decision that supersedes it — the file must never contradict CLAUDE.md.

Expected output:
  - "ซื้อเลย" CTA, shown only when the product's template allows immediate payment.
  - Bottom-sheet form (name / phone / branch / consent checkbox + honeypot), then
    redirect to the returned pay_url.
  - Products that still require a doctor meeting keep the existing view-only page plus
    a "สนใจ ให้ติดต่อกลับ" lead form.

Acceptance Criteria:
  - Core flow completable in ≤ 3 clicks (§9).
  - Loading / empty / error states complete; Desktop / Tablet / Mobile.
  - Price shown must equal the price the order is created at. See risk R1 below.
```

### TASK-138 — Slip verification gaps (human answer: slip AND gateway — slip half)

```
Task: Notify on slip submission + Company-Admin verification queue
Owner: ag-dev + ag-ui
Related: ADR-026 §3.9

Rationale: today POST /pay/{token}/slip writes the file and returns — no event, no
notification. Nobody is told money is waiting. And frontend-admin has NO orders screen
at all, so a Company Admin can confirm via API but has no way to see the queue.

Expected output:
  - Domain event on slip submission → Notification to the order's agent (reuse the
    TASK-053 notification pipeline).
  - frontend-admin: orders queue filtered to awaiting_verification, slip preview,
    confirm / cancel, with the existing OrderPolicy enforced.

Acceptance Criteria:
  - Slip file is still never served from a public URL (existing rule).
  - Confirm action is audit-logged (§6 — money-affecting).
  - tenant isolation must pass.
```

### TASK-139 — Omise payment gateway

```
Task: Omise (Opn Payments) charge + webhook auto-confirmation
Owner: ag-dev
Status: UNBLOCKED — human chose Omise, 2026-08-08 (ADR-026 §5 Q2).
Related: ADR-017, ADR-026 §3.9, §5 Q2, BR-3, BR-4, §6 Secrets

Input:
  - orders.payment_reference — exists since TASK-054, still unused, reserved for exactly
    this. Do not add a new column.
  - Official library: omise/omise-php (composer). Do NOT hand-roll an HTTP client.
  - PaymentMethod enum currently: bank_transfer | promptpay (both slip-verified).

Expected output:
  - A third payment method routed through Omise, using PromptPay (same instrument the
    customer already sees — only the confirmation changes from human to automatic).
  - Webhook endpoint: public, unauthenticated POST, throttled, outside auth:sanctum.
  - OmiseWebhookService: read ONLY the charge id from the body → re-fetch the charge
    from the Omise API → act on the re-fetched status. Never trust the posted payload.
  - On a successful re-fetched charge: write payment_reference, then call the EXISTING
    OrderService::confirmPayment() — do not duplicate its logic, and do not write
    commission_ledger from here (BR-4: commission is a side effect of the pipeline
    reaching Complete Payment, nothing else).
  - Keys in .env only. Per-company Omise keys, if needed, are a separate decision — ask
    before assuming multi-tenant key storage.

Acceptance Criteria:
  - VERIFY the current webhook verification mechanism against live Omise docs before
    implementing (Guardrail 3 — do not assume the API shape from memory). State in the
    PR which doc page was read.
  - Replaying the same webhook event N times produces exactly ONE commission_ledger row
    and one paid_at. Test it by literally calling the endpoint 3 times.
  - A forged webhook naming a real charge id that is NOT actually paid at Omise must not
    mark anything paid — this is the test that proves the re-fetch is real.
  - Amount from Omise must match orders.amount_satang (BR-3, integer satang). A mismatch
    is a hard failure, not a warning.
  - tenant isolation must pass — a webhook must resolve its order without any global
    scope shortcut that could cross companies.
  - Slip verification still works unchanged (it is the fallback, not the loser).

Out of scope: recurring/subscription charges (Omise Schedules API); refunds; per-company
key management; installments.
```

### TASK-140 — Half B QA gate

```
Task: QA — anonymous checkout security
Owner: ag-qa
Acceptance Criteria:
  - IDOR/enumeration: guessed share tokens, guessed pay tokens, guessed order ids.
  - Rate limiting actually enforced on the checkout endpoint (assert 429).
  - Honeypot: a filled hp_field writes nothing but returns the same body as success.
  - PDPA: no client name/phone leaks in any public response or error message.
  - A medical-template product cannot be checked out directly — assert 422.
  - Report actually-run results only.
```

---

## Risks

- **R1 — price mismatch (pre-existing, now customer-visible).** `OrderService` snapshots
  `product.price_satang` (list price), while `product_price_promotions` exists and
  commission is computed promo-aware. Today only an agent sees this. After TASK-137 a
  *customer* sees a promo price and gets charged list price. **This must be resolved
  inside TASK-136**, not deferred — it is the difference between a bug and a consumer
  complaint.
- **R2 — the pay link never expires and cannot be revoked.** `orders.public_token` has
  no expiry, no revoke column, no `signed` middleware — the weakest of the four token
  models in the codebase (every share table has at least `revoked_at`). Acceptable while
  an agent mints the link; less so once anyone on the internet can mint one. Recommend
  adding `expires_at` to orders in TASK-136.
- **R3 — an agent could mint orders for products they never sold** if the BR-1 gate is
  checked on the wrong user. Gate on the *link's* agent, as AffiliateLeadCaptureService
  does — not on the visitor (there isn't one).

## Blocked on the human (BR-7 — do not guess)

1. Names + Thai labels for the post-sale stages (จัดส่ง / นัดใช้บริการ / ติดตามผล). The
   enum has only `OngoingNextMeeting`; the human asked for journeys with post-payment
   steps, so this blocks the "register → pay → after-sales" template shape.
2. Which payment gateway (blocks TASK-139).
3. Duplicate-submit window N (TASK-136).
4. Full payment vs deposit for self-serve customers.
