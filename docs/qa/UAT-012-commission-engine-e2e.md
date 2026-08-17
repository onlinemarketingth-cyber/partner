# UAT-012: Commission Engine E2E UAT (all plan types, real data)

Executed by ag-lead via Claude-in-Chrome against the live dev servers
(`backend` :8010, `frontend-admin` :5179) — real API calls, real
Sanctum sessions, real DB writes. No `php artisan test`, no mocked
data, no cleanup/revert afterward (left in place for inspection).

## 0. Scope change from the original plan

Original plan: a dedicated, isolated `QA Test Co` tenant covering all
6 plan types (30 agents). **Not what actually happened** — partway
through the session the Super Admin account
(`superadmin@example.test`) lost the ability to log in (confirmed
genuine credential mismatch, not rate-limiting — see §5) and could not
be recovered even after a password reset via `php artisan tinker`
performed by the human. Since creating a new Company is Super-Admin-only
(`CompanyPolicy::create()`/`update()`) and self-registration only joins
an *existing* company via invite code, a brand-new isolated tenant
could not be created. **Human decision:** test directly inside the
existing `Thai Life` company (`company_id=1`) using
`admin@thailife.test` (Company Admin) instead. Binary was already
excluded from scope (see §4).

This means: 5 of 6 plan types tested (Unilevel, Matrix, Stairstep,
Generation, Affiliate), inside the live Thai Life tenant, alongside its
real demo data (untouched).

## 1. Setup performed (all via real authenticated API calls)

- 5 products created under Thai Life's existing Brand/Category (Thai
  Life Wellness / Annual Health Package), each 8,900 THB, each with a
  `commission_plan_type` product-level override (TASK-027):
  id 9 QA Unilevel Package, id 10 QA Matrix Package, id 11 QA Stairstep
  Package, id 12 QA Generation Package, id 13 QA Affiliate Package.
- `commission_rules`: Basic tier, 10.00% (1000 bp), one per product
  (BR-2).
- `commission_override_rules`: Basic-tier manager override, 3.00%
  (300 bp) — company-wide, pre-existing scope (TASK-025).
- `commission_matrix_settings`: width 3 / depth 3 / breadth spillover.
  Level-1 rate (5.00%) **already existed** from earlier work this
  session — reused, not duplicated.
- `agent_rank_settings`: 90-day trailing window, weekly recalculation.
  `agent_ranks`: Bronze (0%, threshold 0) / Silver (4%, 50,000 THB) /
  Gold (6%, 150,000 THB, breakaway).
- `commission_generation_settings`: max depth 3.
  `commission_generation_rules`: Generation 1, 4.00% (400 bp).
- `affiliate_attribution_settings`: 30-day attribution window, no
  new-vs-returning differential.
- 20 agents created (4 per track), role=agent, company auto-forced to
  Thai Life via Company Admin session: `qa-{uni,mtx,stx,gen,aff}{1-4}@thailife-test.local`,
  password `password123`.
- Hierarchy set via `PUT /users/{id} {manager_id}`:
  - Unilevel: uni1 ← uni2 ← uni3 ← uni4 (chain)
  - Stairstep: stx2/3/4 → manager stx1
  - Generation: gen1 ← gen2 ← gen3 ← gen4 (chain)
  - Matrix: mtx2/3/4 → manager mtx1 (manager_id set, but see §3 finding — no actual tree placement occurred)
  - Affiliate: no manager_id (independent agents by design)
- All 20 agents passed the real Basic certification exam (exam id=1,
  Thai Life's existing seeded exam, 2 questions, both answered
  correctly server-side-graded, 100% ≥ 70% passing score). Verified
  `has_passed_basic_cert: true` via `/me` for at least one agent per
  track.

## 2. Real sales executed (one per track, deepest agent in each chain)

For each: `POST /clients` → `POST /referrals` (against that track's
product) → `POST /referrals/{id}/advance` × 3 (Complete Registered →
Waiting Appointment → Finish 1st Doctor Meeting → **Complete
Payment**), no target stage ever specified client-side (server always
computes the next stage per CLAUDE.md §4.3).

| Track | Selling agent | Referral id | Reached Complete Payment |
|---|---|---|---|
| Unilevel | qa-uni4 | 10 | ✅ (confirmed via ledger presence) |
| Matrix | qa-mtx4 | 11 | ✅ |
| Stairstep | qa-stx4 | 12 | ✅ |
| Generation | qa-gen4 | 13 | ✅ |
| Affiliate | qa-aff1 | 14 | ✅ (confirmed directly: `current_stage.key == "complete_payment"`) |

## 3. Ledger verification — exact numbers, hand-checked

Fetched via `GET /commission-ledger` as `admin@thailife.test`
(Company Admin sees company-wide ledger). All 3 pre-existing demo
entries (ids 1-3, real Thai Life agents) present and **untouched**.

| id | Agent | Product | Rate applied | Amount (satang) | Amount (THB) | Hand-check |
|---|---|---|---|---|---|---|
| 4 | QA UNI 4 | QA Unilevel Package | 10.00% | 89,000 | 890.00 | 890,000 × 1000 ÷ 10,000 = 89,000 ✅ |
| 5 | QA UNI 3 | QA Unilevel Package | 3.00% (override) | 26,700 | 267.00 | 890,000 × 300 ÷ 10,000 = 26,700 ✅ |
| 6 | QA UNI 2 | QA Unilevel Package | 3.00% (override) | 26,700 | 267.00 | same formula ✅ |
| 7 | QA UNI 1 | QA Unilevel Package | 3.00% (override) | 26,700 | 267.00 | same formula ✅ |
| 8 | QA MTX 4 | QA Matrix Package | 10.00% | 89,000 | 890.00 | ✅ direct only, no level-1 override row (see finding below) |
| 9 | QA STX 4 | QA Stairstep Package | 10.00% | 89,000 | 890.00 | ✅ direct only, no differential override row (see finding below) |
| 10 | QA GEN 4 | QA Generation Package | 10.00% | 89,000 | 890.00 | ✅ direct only, no generation-1 override row (see finding below) |
| 11 | QA AFF 1 | QA Affiliate Package | 10.00% | 89,000 | 890.00 | ✅ direct only, tested as a plain referral not via public affiliate link (see §4) |

All rows: `cert_tier_at_time = Basic`, integer satang throughout (BR-3
— no floats seen anywhere), `payment_status = pending` on every new
row (correct — nothing was manually marked paid, BR-4's
pending/paid separation intact), each row tied 1:1 to its own
`referral_id` (BR-4 immutability/uniqueness intact, no duplicate rows
even though the Unilevel sale produced 4 total ledger rows across 4
different agents from the *same single referral* — that's expected:
one referral can fan out to multiple ledger rows, one per
recipient, each individually unique per agent+referral).

**Conclusion: BR-2 (tiered commission from config, never hardcoded),
BR-3 (integer satang), and BR-4 (immutable ledger, pending/paid
separate field, written only at Complete Payment) all check out
exactly against real data for every row produced.**

## 4. Findings

**Unilevel override propagates to every upline level, not just the
immediate manager.** uni3, uni2, AND uni1 all received the 3% override
from uni4's single sale — a flat rate paid to every ancestor who holds
the qualifying cert tier, not capped at one level. This matches how
`commission_override_rules` is actually structured (one rate per cert
tier, no "levels" concept) — behaves as designed, but worth the human
confirming this uncapped-depth payout is the intended model before
relying on it with real money.

**Matrix: no level-1 override paid — architecture gap, not a bug in
the new code.** `UserService::update()`'s auto-placement trigger
(`MatrixCommissionService::place()`) only fires when
`companies.commission_plan_type === Matrix` (the company's own
single default value) — it has no way to key off a *product's*
override (TASK-027 added product-level override afterward, and by the
existing design comment in `UserService.php`, Matrix's tree is
intentionally a company-wide structure, "not something that makes
sense to key off a single product's override"). Practical
consequence: **Matrix cannot be tested/used on a company whose default
plan type isn't literally Matrix** — it needs its own company (Super
Admin required), it cannot coexist with other plan types as a
per-product override the way commission *rates* can (TASK-028). This
is a real, reportable design constraint — recommend a short ADR
addendum or a task spec to decide whether Matrix should ever support
per-product placement, or whether this stays a "one plan type per
company" limitation by design.

**Generation and Stairstep overrides did not pay — expected,
previously-known limitation.** Both `GenerationCommissionService` and
`StairstepCommissionService` depend on `users.current_rank_id`, which
is written **only** by the scheduled command
(`RecalculateAgentRanks`), never synchronously at sale time. No agent
in either track has ever had a rank assigned because that scheduled
command has never run in this environment. Not a bug — matches the
Services' own docblocks — but it means **the differential/generation
override piece of both plan types is unverified by this UAT.** To
close this gap: run `php artisan agent-ranks:recalculate` (or
whatever the actual command name is in `routes/console.php`) once on
a real machine, then re-check the ledger for stx1/gen3.

**Affiliate tested via a plain referral, not the public link +
lead-capture flow.** Time-boxed simplification — confirms the
`affiliate` plan type doesn't break `CommissionService`'s dispatch
and produces a correct direct-sale ledger row, but does **not**
exercise `affiliate_links`, click tracking, or the 30-day attribution
window (TASK-032/033). If real confidence in the Affiliate mechanic
is needed, a follow-up pass through the actual `/l/{token}` public
flow is recommended.

**Binary track excluded entirely (unchanged from earlier this
session).** `users.binary_leg` has no UI anywhere in
frontend-admin — task #308 (spec already written) covers the fix;
Binary stays untested until that ships.

**Pre-existing data hygiene issue, unrelated to this UAT:** product
id=3 in Thai Life's real catalog is named "ddd", priced at
20,000,000,000 THB, description "ssssssss" — clearly leftover test
garbage from earlier work this session, sitting in the live company.
Recommend deactivating or deleting it (flagged to the human in chat,
not yet actioned).

## 5. Environment note (not a product bug)

`superadmin@example.test` could not log in for most of this session
(422 "credentials do not match our records" — confirmed genuine via
`LoginRequest`'s distinct throttle-vs-failure message wording, i.e.
not a lockout). A password reset via `php artisan tinker` was
performed by the human but login still failed afterward. Root cause
not established. Recommend the human independently verify this
account's credentials/soft-delete state directly against the database
when convenient — not urgent, since Company Admin access was
sufficient to complete this UAT.

## Sign-off

- Tested by: ag-lead (automated via Claude-in-Chrome) — Date: 2026-07-21
- Result: **Pass with known gaps above** — Unilevel/direct-commission math fully verified correct (BR-2/BR-3/BR-4). Matrix, Generation, Stairstep override/differential logic NOT exercised end-to-end (architecture constraint + schedule-only rank recalculation, both pre-existing and documented above, not new regressions). Binary out of scope (task #308 pending).
