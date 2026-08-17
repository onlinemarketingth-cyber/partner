# ADR-006: Commission Configuration Model (Per-Company Setup)

- **Date:** 2026-07-14
- **Status:** Accepted — see Decisions (Round 1-5) below. TASK-023/024/025/026 all implemented (Round 4/5). Binary schema built (Round 4); Binary calculation engine not yet built.
- **Author:** ag-lead

## Decisions (Round 1 — human-confirmed 2026-07-14)

1. **Commission scope (per-product vs. company-wide):** **Option A** — keep the current schema, add an "apply this rate to every product" action in the setup UX. No migration, no change to `CommissionService`'s lookup logic.
2. **Renewal-year commission:** **Wanted, as an admin-configurable setting** (not hardcoded on or off, and not assumed to always equal the first-year rate) — human said "ต้องการแบบ setting ได้". Design details (how renewal is triggered, where the rate lives) are **not yet decided** — see Round 2 questions below.
3. **Agent hierarchy / override commission:** **Wanted.** Levels, override %, and how a manager is assigned are **not yet decided** — see Round 2 questions below.
4. **Split commission between co-selling agents:** **Wanted.** Split ratio and how the second agent is attached to a referral are **not yet decided** — see Round 2 questions below.

Per CLAUDE.md Guardrail 7, none of the undecided details above are assumed — ag-lead is not writing schema/migrations for #2–#4 until the Round 2 questions are answered.

## Context

The human asked ag-lead to research how insurance/sales agent commission ("com") is structured in real systems and industry standards, in order to plan a per-company commission **setup** system — specifically one where a single company can choose to split commission **by product** or apply **one rate overall**.

This ADR is research + design options only. Per CLAUDE.md Guardrails (Section 8) and BR-7, no commission percentage, scope decision, or new business rule is invented here — every open question below is left for the human to decide.

## Research Summary

### 1. Standard commission models (global insurance market)

- **Flat-rate** — one % applied regardless of product.
- **Tiered-by-agent-rank/certification** — rate depends on the agent's certification/rank tier. *(Already this system's BR-2.)*
- **Product-differentiated** — rate varies by product/package (e.g. a 15%-on-new/10%-on-renewal split is typical for a homeowners line, 12%/10% for auto).
- **First-year vs. renewal commission** — new business is paid a much higher % than renewal years, because acquiring a customer costs more than retaining one. Term life commonly runs 50–80% of first-year premium, then 2–5% for years two through ten.
- **Override/hierarchy commission (upline–downline, MGA/FMO structures)** — a recruiter ("upline") earns a percentage of their recruits' ("downline") sales; a single policy can generate commission at the agent level, the agency level, and the upline level simultaneously, each at a different rate.
- **Volume/performance bonus** — an extra layer on top of base commission once a threshold is crossed (e.g. a retention bonus of +2–5% when persistency exceeds 90%, or a cross-sell bonus).
- **Split commission** — when two agents co-sell one policy, the commission is divided between them by an agreed ratio (common splits: 50/50, 60/40, 70/30).

Sources: [Insurance Agent Commission Structure Guide](https://www.sonant.ai/blog/insurance-agent-commission-structure), [Agentero — Commission Structure Explained](https://agentero.com/blog/insurance-agent-commission-structure-explained), [Insurance Pro Agencies](https://insuranceproagencies.com/insurance-agent-commission-structures), [Ritter Insurance Marketing — Insurance Hierarchies](https://ritterim.com/blog/what-are-insurance-hierarchies-how-do-they-work/), [LegalClarity — What Is an Override in Insurance](https://legalclarity.org/what-is-an-override-in-insurance-and-how-it-work/), [OneHQ — Managing Complex Agent Commission Hierarchies](https://www.onehq.com/blog/how-to-manage-complex-agent-commission-hierarchies)

### 2. Thai life-insurance market context

- ค่าคอมมิชชั่นปีแรก โดยทั่วไปอยู่ที่ประมาณ **25–40%** ของเบี้ยประกันปีแรก ขึ้นกับแบบประกันและบริษัท
- ค่าคอมมิชชั่นปีต่ออายุ โดยทั่วไปอยู่ที่ประมาณ **3–5%** ต่อปี ตลอดอายุกรมธรรม์
- รายได้ตัวแทนไทยทั่วไปมักประกอบด้วยหลายชั้น: ค่าคอมจากยอดขาย + รายได้ต่อเนื่องจากการต่ออายุ + โบนัสผลงาน + รายได้จากการสร้างทีม (override) ตามระดับตำแหน่ง (Agent → Unit Manager → Branch Manager)

Sources: [Digital Office — โครงสร้างรายได้ตัวแทนไทยประกันชีวิต](https://digitaloffices.thailife.com/kittitad.pan/articles/%E0%B9%82%E0%B8%84%E0%B8%A3%E0%B8%87%E0%B8%AA%E0%B8%A3%E0%B9%89%E0%B8%B2%E0%B8%87%E0%B8%A3%E0%B8%B2%E0%B8%A2%E0%B9%84%E0%B8%94%E0%B9%89%E0%B8%95%E0%B8%B1%E0%B8%A7%E0%B9%81%E0%B8%97%E0%B8%99%E0%B9%84%E0%B8%97%E0%B8%A2%E0%B8%9B%E0%B8%A3%E0%B8%B0%E0%B8%81%E0%B8%B1%E0%B8%99%E0%B8%8A%E0%B8%B5%E0%B8%A7%E0%B8%B4%E0%B8%95), [899planner — ตัวแทนประกันชีวิต รายได้ต่อเดือนคิดจากอะไรบ้าง](https://www.899planner.com/blog/how-life-insurance-agents-earn-monthly-income.html)

### 3. Software config pattern (multi-tenant SaaS)

The **Rules Engine Pattern** — business logic (rates, thresholds) stored as *data* in the database rather than hardcoded, so changes need no deployment — is the standard approach for exactly this kind of per-tenant configurability, and is already this project's own decision (BR-2/BR-7: "Actual rates live in the `commission_rules` config table — never hardcode numbers"). A common refinement of this pattern is **"specific overrides general"**: a nullable scope column where `NULL` means "default for everything in this scope" and a filled-in value means "an exception for this one case" — avoiding the need to duplicate an identical rate row for every product one-by-one.

Source: [Medium — The Ultimate Multifunctional Database Table Design: Rules Engine Pattern](https://medium.com/@herihermawan/the-ultimate-multifunctional-database-table-design-rules-engine-pattern-d55460f048c4)

## Current State of This System (verified against the real schema, not assumed)

- `commission_rules` (migration `2026_07_09_100050`): every row requires **both** `cert_tier_id` **and** `product_id` (both `NOT NULL` foreign keys), scoped to `company_id`. This means:
  - "แยกตามสินค้า" (split by product) **already works today** — just create one rule row per product.
  - "ภาพรวมทั้งบริษัท" (one overall rate) is **only achievable indirectly** — an Admin must enter the *same* rate on every product's row, one at a time. There is no single "apply to everything" setting.
- `commission_ledger` (BR-4) fires exactly **once per referral**, at the Complete Payment pipeline stage, and snapshots the rate that applied at that moment. There is currently **no first-year-vs-renewal distinction anywhere** — even though the product itself is an *annual subscription package* that a client would presumably renew. This gap was not something CLAUDE.md/BR-2..7 ever addressed, so it is flagged here rather than assumed either way.
- There is **no agent hierarchy** (no upline/downline, no manager-override concept) anywhere in this system — every Agent is a flat peer under a Company Admin.
- There is **no split-commission** mechanism between two co-selling agents — a referral has exactly one `referring_agent_id`.

## Options Considered (for "split by product vs. overall")

**Option A — Keep the current schema, improve only the setup UX.** Add an "Apply this rate to every product" action in the Admin config screen; the backend loops and creates/updates one row per product behind the scenes. No schema/migration change, no risk to the existing `commission_ledger` snapshot behavior.
- Pros: low risk, ships fast, `commission_ledger`'s existing snapshot logic is untouched.
- Cons: not a true "default" — adding a brand-new product later does **not** automatically inherit the company's chosen overall rate; an Admin must re-apply it.

**Option B — Make `product_id` nullable as a real "company default" row.** `NULL` `product_id` = the company-wide default rate (applies to any product that has no more specific row); a filled-in `product_id` = an explicit override for that one product. This is the "specific overrides general" pattern found in the research above.
- Pros: matches the standard pattern; a newly-added product automatically inherits the default with zero extra setup.
- Cons: requires a migration (`product_id` nullable) and a lookup-logic change in `CommissionService` (fall back to the `NULL` row when no exact match exists) — touches working, tested BR-4 logic, so needs full regression testing before shipping.

Neither option is chosen here — this is presented as a trade-off for the human to decide (CLAUDE.md Guardrail 7).

## Open Questions — Round 1 (answered 2026-07-14, see Decisions above)

1. ~~Option A or Option B for the "split by product vs. overall" setup?~~ → Option A.
2. ~~Renewal-year commission — new rule or not?~~ → Wanted, admin-configurable.
3. ~~Agent hierarchy/override — wanted or stay flat?~~ → Wanted.
4. ~~Split commission between co-selling agents — wanted or not?~~ → Wanted.

## Decisions — Round 2 & 3 (answered 2026-07-14)

- **Renewal trigger:** automatic, based on a due date — not a manual "mark as renewed" button. Requires a scheduled job (reuses ADR-004's `database`-queue + `Schedule::command()` infrastructure, same pattern as TASK-016's follow-up reminders).
- **Renewal repeat behavior:** **admin-configurable per rule** — not hardcoded to "once" or "every year forever". A company can set either, per (cert tier × product).
- **Hierarchy depth:** multi-level (e.g. Agent → Unit Manager → Branch Manager), not capped at one level.
- **Override rate basis:** based on the **manager's own** cert tier/rank, not the original selling agent's — i.e. a second, manager-facing rate table, config-driven (BR-2/BR-7 style, never hardcoded).
- **Split ratio:** chosen per-referral (not one fixed company-wide split like always-50/50).

## Design (ag-lead judgment calls — technical/UX shape, not new business values; flag if any of these should change)

- **Manager relationship:** a nullable, self-referencing `users.manager_id` (→ `users.id`, same company only, enforced in the Service layer). This alone produces the multi-level chain the human asked for — "Unit Manager" and "Branch Manager" are just agents whose own `manager_id` points further up; no new role/enum is needed, and there is no artificial level cap. Assigned by a Company Admin from the existing "Manage Agents" screen (a new dropdown, same pattern as the existing role-change control).
- **Override commission storage:** a new `commission_override_rules` table (`company_id`, `manager_cert_tier_id`, `rate_type`, `rate_value`, `effective_from/to`) — same shape as `commission_rules`, keyed by the manager's tier instead of the seller's. When a normal sale ledger entry is created, `CommissionService` walks the `manager_id` chain upward and creates one **additional**, separate `commission_ledger` row per manager found (never rewrites the original — BR-4 immutability preserved). `commission_ledger` gains `earned_via` (`direct` | `override`) and a nullable `override_source_agent_id` so reports can distinguish "my own sales" from "my team's overrides".
- **Renewal storage:** `commission_rules` gains two nullable columns — `renewal_rate_type`, `renewal_rate_value` (`NULL` = no renewal commission configured for that rule, fully opt-in) — plus `renewal_recurs` (boolean, default `false`) for the admin-configurable repeat behavior. A new `next_renewal_date` is stamped on the referral (or a small new `client_subscriptions` row) when the Complete Payment ledger entry fires; a daily scheduled command finds due renewals, creates a new (still immutable) ledger entry at the renewal rate, and either advances the date by a year (`renewal_recurs = true`) or stops (`false`).
- **Split commission:** `referrals` gains a nullable `co_agent_id` and a `split_percentage` (the co-agent's share; the primary `referring_agent_id` keeps the remainder) — exactly a pair, matching the scope actually asked for (not an open-ended N-way split). `CommissionService` creates two ledger rows instead of one whenever `co_agent_id` is set.

## Consequences

- This is a large, multi-part change touching `CommissionService`, `commission_ledger`'s shape, `users`, and `referrals` — broken into 4 separate task specs (TASK-023..026) rather than one, so each can be reviewed/tested independently and shipped without blocking the others.
- Every new rate/flag above lives in config tables or nullable columns, never hardcoded (BR-7) — a company that doesn't want renewal/hierarchy/split commission simply never fills those fields in, and the system behaves exactly as it does today for them.
- `commission_ledger`'s new `earned_via`/`override_source_agent_id` columns are additive — existing rows backfill to `earned_via = 'direct'`, nothing about historical ledger data changes retroactively (BR-4).

## Addendum — Direct-Selling (MLM) Plan Taxonomy Check (2026-07-14)

Before coding TASK-025, the human asked ag-lead to check TASK-025's design against the standard direct-selling/network-marketing compensation plan types, to see whether "หลายแบบ" (the several known industry patterns) are covered or need more work.

**The 6 standard plan types (industry taxonomy):**

- **Unilevel** — unlimited frontline width, a fixed number of levels deep, each level has its own %.
- **Binary** — exactly two legs per person; new recruits who don't fit are automatically placed ("spillover") into whichever leg needs volume.
- **Matrix** — width AND depth are both capped (e.g. 3×5); once a level fills, new recruits spill into the next open slot.
- **Stairstep/Breakaway** — a distributor "breaks away" into their own independent tree once they hit a rank, cutting the direct override link to their old upline from that point on.
- **Generation plan** — a Unilevel variant that pays by *generation* (a rank-based grouping of the downline) instead of by literal level number.
- **Hybrid** — any deliberate mix of the above (e.g. Unilevel for the org structure, Binary for the payout math).

Sources: [MLM Trees — Types of MLM Compensation Plans](https://mlmtrees.com/blog/types-of-mlm-compensation-plans/), [FlawlessMLM — Binary vs Unilevel vs Matrix](https://flawlessmlm.com/en/blog/binary-vs-unilevel-vs-matrix-mlm-plan), [Epixel — Types of MLM Compensation Plans](https://www.epixelmlmsoftware.com/blog/types-of-mlm-compensation-plans), [HybridMLM — Compensation Plans Compared](https://www.hybridmlm.io/blogs/mlm-compensation-plans-compared-binary-matrix-and-unilevel-explained/)

**Where TASK-025's design sits:** the `manager_id` self-referencing chain (unlimited width, unlimited depth, no forced placement) is structurally a **Unilevel** plan — the closest match, and (ag-lead's own assessment, not a sourced fact) the type actually used by real insurance agency hierarchies (FMO → MGA → GA → Agent, per the ADR-005/earlier research in this doc) — every one of those relationships is a deliberate appointment/contract, never an automatic "spillover" placement. Binary and Matrix both fundamentally depend on *automatic* placement of new recruits into whichever leg/slot needs volume, which doesn't obviously make sense for a licensed insurance agent (an agent's manager should always be a deliberate choice, not automatic placement) — flagged as ag-lead's reasoning for the human to confirm or overrule, not decided unilaterally.

**Two gaps versus a textbook Unilevel plan, found during this check (not yet decided — see questions below):**
1. TASK-025 currently has **no depth cap** — it walks the `manager_id` chain all the way to the top every time. A textbook Unilevel plan typically pays only a fixed number of levels deep (5–10), not infinitely.
2. TASK-025's override rate is keyed by **the manager's own cert tier** (already decided, Round 2) rather than by **which level deep** the override is (the textbook Unilevel approach — Level 1 override might be 5%, Level 2 might be 3%, etc., regardless of the manager's tier). This was a deliberate choice already made by the human, noted here only so the deviation from the textbook pattern is on record.

Binary, Matrix, Stairstep/Breakaway, and Generation plans are **not** in any current task spec — none of TASK-023..026 build spillover, forced placement, or breakaway-tree logic.

**Human decision (2026-07-14):** Binary and/or Matrix support **is wanted**, in addition to TASK-025's Unilevel design; the override depth stays uncapped as already planned.

This is a materially bigger scope than TASK-025 as written, for one specific reason worth spelling out: real Binary plans don't pay a simple "% of each sale" — they pay on **matched volume between the two legs**, calculated in periodic cycles (daily/weekly), usually with a per-cycle payout cap and a rule for what happens to unmatched leftover volume. That's a different calculation engine, not just an extra config field on the existing design.

**Round 3 decisions (2026-07-14):**
- A company picks exactly **one** plan type overall (not mixed per-product within one company).
- Binary spillover placement: the **referrer chooses left/right** when adding a new recruit — never automatic.
- Matrix width×depth: **admin-configurable per company** (not a fixed platform-wide size).
- Binary calculation mechanic (matched-volume cycles vs. simple per-leg %): **still undecided** — the human asked to see a worked example of both before choosing; a comparison visual was shown (matched-volume: 30,000 matched → 3,000 paid this cycle, 20,000 carries over, needs a scheduled cycle job + cap; simple %: 80,000 total × rate → paid instantly per sale, reuses TASK-025's existing override mechanism almost as-is). No task spec for Binary calculation is written until this is chosen.

## Round 4 — Binary schema built now, standard-first rollout (2026-07-14)

**Decision A (calculation mechanic):** the human chose **matched-volume cycles** (Option A from the comparison visual) — the real/standard Binary mechanic, not the simplified per-leg-% shortcut. This confirms Binary needs its own calculation engine (a scheduled cycle job, a per-agent running leg-volume balance, and a payout-cap/carry-over policy), not just a config flag layered onto TASK-025's existing override mechanism.

**Decision B (rollout sequencing):** *"A เพื่อวางโครงสร้าง DB ให้รองรับและ Ui ให้รองรับทั้งแบบ standard และแบบ binary แต่เราจะทำที่ standard ก่อน"* — build the DB schema and admin UI to support **both** Unilevel(Standard) and Binary now, but only Unilevel gets a working `CommissionService` implementation this sprint (i.e. TASK-025 as already specced). Binary is visible and selectable, not silently hidden.

**Decision C (build Binary tables now):** *"สร้างตาราง Binary ไว้เลย แต่ขึ้นกำลังพัฒนา"* — rather than only reserving placeholder columns, the full Binary-specific schema is created now (this session), with the admin UI marking Binary as **"อยู่ระหว่างพัฒนา"** (under development) wherever it's selectable, since no job/service produces real Binary payouts yet.

**ag-lead finding, flagged per Guardrail 6 (not fixed silently):** while pattern-matching `CommissionLedger` against this new schema, found that `2026_07_09_200000_add_unique_referral_id_to_commission_ledger_table` enforces **at most one `commission_ledger` row per referral** at the DB level. That migration's own comment already anticipated this: *"Flag to a human if a future business rule ever needs multiple commission events per referral (e.g. renewal commissions) — this constraint and the Service's current assumption would both need revisiting together."* That is now true for TASK-024 (renewal — repeat rows on the same referral), TASK-025 (override — extra rows stemming from the same referral, credited to different managers), TASK-026 (split — two rows on the same referral), and Binary (a matched-volume cycle row that isn't tied to any single referral at all). **Fix applied:** the unique constraint is dropped; "at most one *direct-sale* row per referral" remains the intended invariant but is enforced in `CommissionService`'s application-code guard only (same "DB constraint was only ever the backstop" philosophy the original migration documented), since no single composite DB key stays valid across recurring renewals either.

**Schema delivered this session (migrations `2026_07_14_130000` .. `2026_07_14_200000`):**
- `commission_ledger`: dropped `commission_ledger_referral_unique`; `referral_id`/`cert_tier_id_at_time`/`product_id` made nullable (only a future `binary_match` row legitimately omits them — direct/renewal/override rows still always set them, enforced in the Service layer); added `earned_via` (`App\Enums\CommissionEarnedVia`: direct/renewal/override/binary_match, default `direct` so every existing row backfills correctly), `override_source_agent_id` (nullable FK → users), `source_binary_cycle_id` (nullable FK → the new `binary_matching_cycles`).
- `companies.commission_plan_type` (`App\Enums\CommissionPlanType`: unilevel/binary, default `unilevel` — zero behavior change for every existing company).
- `users.manager_id` (nullable, self-referencing, `nullOnDelete`, shared by both plan types — TASK-025) and `users.binary_leg` (`App\Enums\BinaryLeg`: left/right, nullable, only meaningful under a Binary plan).
- `commission_override_rules` (TASK-025, Unilevel — company_id, manager_cert_tier_id, rate_type, rate_value, effective_from/to).
- `commission_binary_settings` (new, one row per company — matched_rate_type/value, cycle_frequency (`App\Enums\BinaryCycleFrequency`: weekly/biweekly/monthly), payout_cap_satang (nullable = uncapped), carry_over_unmatched).
- `binary_leg_volumes` (new, one row per agent — running left_volume_satang/right_volume_satang balance, last_cycle_at).
- `binary_matching_cycles` (new, one row per agent per cycle run — period_start/end, left/right/matched/unmatched_carried volume snapshots, nullable `commission_ledger_id` back-link — null when a cycle matched zero volume, since BR-4/TASK-025 precedent says never create a $0 ledger row).

All Binary-specific models (`CommissionBinarySetting`, `BinaryLegVolume`, `BinaryMatchingCycle`) and columns exist and are TenantScoped, but nothing in `CommissionService` reads or writes them yet — they are inert until a future task actually builds the matching-cycle job. `frontend-admin`'s Company form/list now expose a plan-type selector; choosing Binary shows an inline "อยู่ระหว่างพัฒนา" notice.

## Round 5 — TASK-023, TASK-024, TASK-026 implemented (2026-07-14/15)

Per the human's instruction *"Commission ทำต่อ task 23 24 26"*, all three were implemented end to end (TASK-025 was already done in Round 4's session). Decisions made along the way, per Guardrail 7 (present trade-offs, don't guess at length):

**TASK-023 (setup UX)** — built exactly as Round 1's Option A: an "apply this rate to every product" checkbox in `ProductCatalogView.vue`'s commission-rule form, looping POST (new) / PUT (existing rule for that product+tier) per product, reporting every outcome. One-time apply, not a standing default (documented inline and in the UI itself with an amber notice) — a product added later still needs the rate re-applied.

**TASK-024 (renewal)** — schema: `commission_rules` gains `renewal_rate_type`/`renewal_rate_value`/`renewal_recurs` (all nullable/opt-in); `referrals` gains `next_renewal_date`. `CommissionService::recordForReferral()` stamps `next_renewal_date = now()->addYear()` only when the firing rule has a renewal rate configured. A new daily scheduled command (`DispatchDueRenewalCommissions`, ADR-004 claim-then-act pattern) re-looks-up the CURRENT `commission_rules` row by the original sale's snapshot (product + cert tier at time of sale) at renewal time — so a later rate edit affects only future renewal cycles, never past ones. `renewal_recurs` (admin-configurable) decides whether the cycle repeats or fires once.

**TASK-026 (split commission)** — three decisions worth flagging, since the task spec (written by ag-lead from the human's backlog item, not a CLAUDE.md BR) left them open:

1. **`split_percentage` semantics.** Defined as the % of the total direct-sale commission that goes to `co_agent_id`; the referring agent (`referrals.agent_id`) gets the remainder. Any 1-satang BR-3 rounding gap always lands on the referring agent, never the co-agent — same "remainder goes to the primary party" rule already used for TASK-025's overrides.
2. **Referrals stay otherwise immutable.** `ReferralPolicy` documents a deliberate "no update()/destroy()" design (a referral is a sales-audit record). Rather than reopening that with a generic PUT, `co_agent_id`/`split_percentage` are editable ONLY through one new narrow, named-ability endpoint — `PATCH /referrals/{referral}/co-agent` — mirroring how `/advance` already works. It's blocked once the referral reaches Complete Payment (BR-4: the direct-sale ledger row, possibly already split, is immutable by then).
3. **Agent Portal needs a co-agent picker, which needs a name.** `UserPolicy::viewAny()` restricts the `/users` listing endpoint to Company Admin/Super Admin — a plain Agent has no existing way to see a teammate's name. Rather than relaxing that policy (which would expose full user records), a new minimal endpoint `GET /referrals/co-agent-options` was added, returning **id + name only** for this company's other agents, scoped by the same `TenantScope` as everything else. This is a deliberate, narrow exception to Section 5 rule 4 ("Agent sees only own records") — flagged here per Guardrail 6 rather than added silently.

**Explicitly out of scope / not extended (flagged, not decided by ag-lead):** a co-agent's own manager does NOT earn a TASK-025 override on the co-agent's split (only the referring agent's manager chain is walked) — the task spec was silent on this. Renewal-year commissions (TASK-024) are also NOT split even when the original sale was — the renewal command always pays 100% to the referring agent. // TODO: CONFIRM (business rule) if a future task should extend either of these.

**Files:** migrations `2026_07_14_210000`..`2026_07_14_230000`; `CommissionService::recordDirectSale()` (new, split-aware, replaces the inline creation that used to live directly in `recordForReferral()`); `DispatchDueRenewalCommissions` command + `routes/console.php` schedule; `ReferralService::setCoAgent()`; `SetCoAgentRequest`; `ReferralController::coAgentOptions()`/`setCoAgent()`; `ProductCatalogView.vue` (renewal fields) and `frontend/src/views/ReferralsView.vue` (split toggle + co-agent picker + post-creation editor), both Agent Portal and Admin.

**Still not decided / out of scope for this session:** the actual `CommissionService` matched-volume calculation logic, the scheduled cycle job, and any Matrix-plan schema (Matrix was discussed in Round 3 but the human's most recent instructions only asked for Binary's tables specifically — Matrix remains a documented future option, not built).
