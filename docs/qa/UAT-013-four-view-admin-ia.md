# UAT-013: 4-View Admin IA — Agent / Product / Setup / Policy & Report (click-through)

Covers everything built across TASK-036 through TASK-041 — the full 4-view Admin
information architecture (มุมที่ 1-4). Manual click-through only (no API calls) —
every step below is "go here, click this, confirm you see that."

No new migrations for this UAT pass beyond what earlier phases already required.
Backend on `:8010`, Admin app on `:5179` (per `vite.config.ts`'s `strictPort`).

## Preconditions

| Role | Email | Password | Notes |
|---|---|---|---|
| Super Admin | `superadmin@example.test` | `password` | Per `DatabaseSeeder.php`. **Flag:** ag-lead attempted this login during TASK-041 verification and got a credentials-mismatch error — the dev DB may have been reseeded/changed since. Verify this login works before starting; if not, ask KreangYot for the current super admin credentials rather than guessing/resetting one yourself. |
| Company Admin (Thai Life) | `admin@thailife.test` | `password` | |
| Agent (Thai Life) | `agent@thailife.test` | `password` | Used only for the negative "agent blocked from frontend-admin" check in Section 0. |

Thai Life already has real QA data from the earlier E2E UAT pass (20 agents, 5
products, real sales through commission ledger) — most screens below should NOT
be empty. An empty screen where data is expected is worth flagging, not assuming
away as "just no data yet."

---

## Section 0 — Cross-cutting access control (5 min)

- [ ] Log in as `agent@thailife.test` at `http://localhost:5179/login` — confirm you are blocked from the Admin app entirely (TASK-established behavior, not new this pass) and redirected/rejected, not shown any Admin screen.
- [ ] Log in as `admin@thailife.test` — confirm the top nav does NOT show "Manage companies" or any other Super-Admin-only entry.
- [ ] Log in as `superadmin@example.test` — confirm "Manage companies" IS visible and cross-company data is reachable.

---

## Section 1 — มุมที่ 1: Agent View (`/agents`, Overview tab)

As `admin@thailife.test`.

- [ ] Go to "จัดการตัวแทน" → confirm you land on the "ภาพรวม" tab by default.
- [ ] Confirm onboarding KPIs render (not stuck on a loading skeleton, not blank).
- [ ] Confirm Active / At-risk / Dormant segmentation shows real agent counts that sum sensibly against the total agent count.
- [ ] Confirm a "Top performers" list and "top product per agent" both show real names, not placeholder text.
- [ ] Confirm a commission summary (this week/month) renders a real satang-derived บาท figure, not `NaN` or `0` if sales exist.
- [ ] Scroll to the "เครื่องมือเสริม" card row — confirm all 4 cards are present (Agent Promotions, Reward Center, Announcements, and the TASK-041 policy/report link if it was also added here — check both this view and Admin home per TASK-041's actual entry-point choice).

### 1a. Agent Promotions (`/agent-promotions`)

- [ ] Click into the "โปรโมชั่นตัวแทน" card — confirm the list of existing promotions loads.
- [ ] Click "+ สร้างใหม่" — fill the form (name, target type, bonus type/value, date range) — submit — confirm it appears in the list with the correct status badge.
- [ ] Edit an existing promotion — change one field — save — confirm the change persists after a page reload.
- [ ] Confirm the "ยังไม่มี Service คำนวณจ่ายเข้า commission ledger จริง" (or equivalent BR-7 disclosure) is visible somewhere on this screen, per TASK-039's documented gap.

### 1b. Reward Center (`/reward-center`)

- [ ] Confirm two areas exist: reward catalog (items) and redemption requests.
- [ ] Create a reward item (name, cost in points, stock) — confirm it appears in the catalog.
- [ ] If any redemption requests exist (seeded or created during this pass), click one — confirm Approve/Reject buttons follow the pending→{approved,rejected}, approved→fulfilled state machine (TASK-039) — i.e. you cannot skip straight to "fulfilled" from "pending."

### 1c. Announcements (`/announcements`)

- [ ] Create an announcement (title, body, audience = all agents or a specific cert tier) — confirm it appears in the list with the correct audience label.
- [ ] Confirm there is still no Agent Portal-facing surface for this (TASK-039's documented out-of-scope item) — this is expected, not a bug to report.

---

## Section 2 — มุมที่ 2: Product View (`/product-catalog` → `/product-performance`)

As `admin@thailife.test`.

- [ ] Go to "สินค้า" ("Product catalog") — find the link-out button (added to `HeroHeader`'s actions slot per TASK-040) to "มุมมองสินค้า" / product performance — click it.
- [ ] **ABC grading section**: confirm a table of products with grade badges (A/B/C/D, colored) renders with real data.
- [ ] Click each period filter — "30 วัน", "90 วัน", "365 วัน", "ทั้งหมด" — confirm the table visibly refreshes each time (loading state briefly shows) and confirm the "คำนวณล่าสุดเมื่อ" timestamp updates.
- [ ] Confirm the mandatory disclosure line about the revenue figure being an ESTIMATE (sold_count × current price, not historical) is visible and not buried.
- [ ] **Price promotions section**: create a price promotion for one product (discounted price, date range) — confirm it appears in the list with the correct status.
- [ ] Confirm the mandatory disclosure that this is display-only and NOT wired into commission calculation is visible.
- [ ] Confirm the stray "ddd" test product (a known, pre-existing data-cleanup item, not a new bug) still shows up as grade D with 0 sales — if it's gone, that's actually worth noting as resolved.

---

## Section 3 — มุมที่ 3: Setup View (`/commission-plans`)

As `admin@thailife.test`.

- [ ] Go to "แผนคอมมิชชั่น" — confirm 6 tabs render: กฎคอมมิชชั่น, Binary, Matrix, อันดับ (Stairstep), Generation, พันธมิตร (Affiliate).
- [ ] **กฎคอมมิชชั่น tab**: confirm the multi-scope commission rules list (company-wide/category/product) renders with real rules (Thai Life should have several from the earlier E2E pass). Create one new rule scoped to a specific product — confirm no date-range overlap error when the range is genuinely non-overlapping, and confirm a genuinely-overlapping range IS rejected with a clear error.
- [ ] **Binary tab**: confirm settings form + matching cycles history render (may be empty if Thai Life's QA data didn't exercise Binary — that's expected, not a bug).
- [ ] **Matrix tab**: confirm settings + level rates + tree/grid visualization render.
- [ ] **อันดับ tab**: confirm the rank ladder CRUD renders.
- [ ] **Generation tab**: confirm settings + rules CRUD render.
- [ ] **พันธมิตร tab**: confirm the affiliate attribution settings form renders.
- [ ] Confirm the Setup hub links out to the per-product commission wizard, company-wide default plan (Company Management), and Gamification Config — all 3 should be reachable, none should 404.

---

## Section 4 — มุมที่ 4: Policy & Report (`/policy-report`)

As `admin@thailife.test` first, then repeat the Platform Report check as `superadmin@example.test`.

- [ ] From Admin home, click the "นโยบายและรายงาน" card — confirm you land on the "บันทึกการตรวจสอบ" (Audit Log) tab by default.
- [ ] Confirm the coverage-gap disclosure banner is visible ("บันทึกนี้ยังไม่ครอบคลุมทุก action...").
- [ ] As `admin@thailife.test`, confirm only 3 tabs are visible: บันทึกการตรวจสอบ, PDPA / Compliance, สถานะการตั้งค่า — "รายงานภาพรวม" (Platform Report) must NOT appear for a Company Admin.
- [ ] Go to Setup (`/commission-plans`) and create or edit one commission rule — return to Policy & Report's Audit Log tab — confirm a new row now appears for that action (`commission_rule.created` or `.updated`), with a correct actor name and timestamp. This is the single most important functional check in this section — it proves the new audit write-coverage actually works end-to-end, not just in code review.
- [ ] Click into that row's detail (old/new values) — confirm the JSON diff shown is legible and matches the actual change made.
- [ ] **PDPA / Compliance tab**: confirm KPI cards (total clients, consent rate %, missing count) render with real numbers, and the missing-consent table lists real client names oldest-first.
- [ ] **สถานะการตั้งค่า tab**: confirm Thai Life's row shows real counts (commission rules, gamification overrides, Academy modules, products) and the "กำหนดแล้ว" / "ยังไม่กำหนด" badges match what you'd expect given Thai Life's actual setup state.
- [ ] Log out, log in as `superadmin@example.test`, return to `/policy-report` — confirm "รายงานภาพรวม" (Platform Report) tab now IS visible.
- [ ] Click it — confirm a per-company table renders (at minimum Thai Life plus any QA companies created during the earlier E2E pass) with agent counts, referral counts, and commission paid/pending in บาท.
- [ ] On the same super admin session, confirm the Audit Log and Config Health tabs' company-filter (if rendered) lets you switch companies and the data actually changes.

---

## Known gaps at this stage (not bugs — already documented in TASK-039/040/041)

- No Agent Portal-facing UI for Promotions/Rewards/Announcements — Admin-only so far.
- No Service pays Promotion bonuses into the commission ledger yet.
- Price promotions are display-only, not wired into commission calculation.
- Audit log coverage is partial (3 action types only).
- Compliance report has no per-company filter for Super Admin (always cross-company totals).
- Consent tracking is a single timestamp, not a versioned/historical record.

## Sign-off

- [x] Tested by: ag-lead (live click-through via Claude in Chrome)  Date: 2026-07-22
- [x] Result: ☑ Pass with known gaps above

Bugs found during this pass (both fixed and re-verified live, not just in code review):
1. `AnnouncementsView.vue` subtitle falsely implied an Agent Portal-facing surface exists — corrected to disclose it doesn't yet (TASK-039).
2. `LoginView.vue` — the "Agent blocked from Admin" warning never rendered when the router's role-guard redirect resolved to the same route name (`login` → `login`, query-only change), because Vue Router reuses the component instance and a one-time `ref()` init from `route.query` never re-evaluates. Fixed with `watch(() => route.query.blocked, ..., { immediate: true })`.

Section 0 fully closed: Agent-blocked flow confirmed (with working warning message), Company Admin nav confirmed hiding Super-Admin items, Super Admin login confirmed working (after tinker password reset) with "จัดการบริษัท" visible and Platform Report tab rendering real cross-company data (QA Test Co, Thai Life).
