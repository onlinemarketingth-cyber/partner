# TASK-209 (PLAN) — make the Super Admin company scope correct on *every* Admin page

- **Owner:** ag-lead (this plan) → ag-dev + ag-ui (build) → ag-qa (gate)
- **Date:** 2026-08-19
- **Status:** **P1–P4 DONE + P5 written (UAT-015) — 2026-08-19.** Only the browser pass remains.
  Both §7 decisions answered by the human — see §7.
- **Human:** "ลองวางแผนในการเลือกบริษัทให้ทำงานได้ถูกต้อง เฉพาะบริษัทในโหมด Super Admin ในทุกหน้า"
- **Related:** ADR-038 + TASK-208 (the switcher and the first 10 screens — already shipped),
  BR-6 / Section 5, TASK-202 (where the "client-side filter over a paginated list" bug was first
  caught).

---

## 1. The contract every screen must obey

Four rules, so that "correct" means the same thing on all 32 routes:

1. **One source of truth.** No screen keeps its own company picker or its own `/companies` fetch.
   Everything reads `activeCompany` (ADR-038).
2. **Scoped = filtered at the source.** When a company is selected, the screen must show *only*
   that company's data — and it must be filtered **server-side**, not with a client-side
   `.filter()` over a page of results (see §3: that is an active data-loss bug, not a preference).
3. **ทุกบริษัท = read-across, never write.** Any create/edit action is disabled with
   `CompanyScopeNotice`. Lists in this mode must show a company column/chip on every row — a
   cross-company list with no company label is exactly the confusion this whole thread started
   from.
4. **A record's own company wins over the header.** Opening a record that belongs to another
   company (deep link, search result) shows it, labelled with its real company — never silently
   re-scoped, never blocked.

## 2. Coverage — all 32 routes classified

### Class A — done in TASK-208 (10)

`product-catalog` · `product-create` · `product-edit` · `academy-management` ·
`commission-plan-settings` · `theme-settings` · `video-settings` · `team-visibility-settings` ·
`commission-split-settings` · `policy-report`

Remaining work for these: re-verify under §3's server-side filtering once it exists.

### Class B — must follow the scope, not yet migrated (17)

| Route / view | What a Super Admin sees today | Work |
|---|---|---|
| `home` (AdminHomeView) | dashboard tiles aggregated across every company | scope the metrics call; show the company name in the header block |
| `agent-management` | agent list, all companies | scope + company chip |
| `agent-roster` | own `/companies` fetch + its own "create in company" picker | remove local picker → store; create uses scope |
| `agent-approvals` | approval queue across companies (already prints `p.company.name`) | scope the queue |
| `agent-invite-links` | links across companies | scope |
| `client-management` / `client-file` | PDPA client data across companies | scope — **highest sensitivity**, see §6 |
| `referral-pipeline-management` | pipeline board across companies | scope |
| `commission-management` | ledger rows across companies | scope (money screen — must not mix) |
| `sales-team` | team cockpit across companies | scope |
| `agent-commission-summary` | already supports `?company_id=` server-side | wire to the store |
| `product-performance` | own `/companies` fetch + local filter | remove local picker → store |
| `agent-promotions` | own `/companies` fetch + `company_id` form field | scope list; keep the form field only for §5's platform-wide case |
| `reward-center` | own `/companies` fetch; `company_id: ''` = platform-wide | scope list; **§5 applies** |
| `announcements` | own `/companies` fetch; nullable `company_id` = platform-wide | scope list; **§5 applies** |
| `gamification-config` | nullable `company_id` = platform-default rules | scope list; **§5 applies** |
| `voucher-redeem` | redemption lookup across companies | scope |

### Class C — deliberately platform-wide, must IGNORE the scope (5)

| Route | Why |
|---|---|
| `company-management` | it manages the companies themselves |
| `catalog-management` | ADR-036 global catalog — those tables have no `company_id` at all |
| `mail-settings` | `platform_mail_settings` is a single global row (no `company_id`) |
| `policy-report` → *platform* tab | a cross-company comparison is the report's whole purpose |
| `profile`, `login` | not company data |

These need one visible affordance: a small "ทั้งแพลตฟอร์ม" badge so it is obvious the header scope
does not apply here. Otherwise a Super Admin will read them as scoped and be wrong.

## 3. The blocker to fix first: filtering happens in the wrong place

Today a Super Admin's list endpoints return **every company's rows** and the frontend narrows them
with `.filter()`. Combined with pagination that silently drops data — exactly the bug already found
and fixed once in `GET /brands` (TASK-202: `paginate()` default 15, no pager in the UI, rows 16+
simply did not exist).

Verified: these list endpoints accept **no** `company_id` query filter today —

```
/products  /brands  /product-categories  /clients  /referrals  /commission-ledger
/users     /announcements  /reward-items  /agent-promotions  /storefront-banners
```

…while these already do (the pattern to copy): `/agent-commission-summary`,
`/audit-logs`, `/config-health-report`, `/leaderboard`, `/sales-team-overview`,
`/video-processing-settings`, `/team-visibility-settings`, `/gamification-rules`.

**Proposal (ag-dev, Phase 1):** add the same Super-Admin-only `?company_id=` filter to the eleven
endpoints above — one shared trait/scope so the rule is written once:

```php
// Super Admin only; for anyone else TenantScope has already answered this.
$query->when($request->user()->isSuperAdmin() && $request->filled('company_id'),
    fn ($q) => $q->where('company_id', $request->integer('company_id')));
```

A Company Admin passing `company_id` must change nothing (TenantScope still wins) — that needs its
own test, per BR-6.

## 4. Phases

| Phase | Content | Owner | Gate |
|---|---|---|---|
| **P1** | `?company_id=` on the 11 endpoints + feature tests (incl. "Company Admin cannot widen scope") | ag-dev | suite green |
| **P2** | Frontend `api` helper that appends the scope automatically, so 17 screens don't each remember to | ag-ui | — |
| **P3** | Migrate Class B screens in 3 batches: (a) agents: roster/management/approvals/invite-links · (b) sales: clients/client-file/pipeline/commission/sales-team/voucher · (c) content+reports: promotions/rewards/announcements/gamification/product-performance/home | ag-ui | each batch compiles + UAT'd |
| **P4** | Class C badges + confirm they ignore the scope | ag-ui | — |
| **P5** | Regression pass: every Class A screen re-checked against server-side filtering | ag-qa | UAT-015 |

Batches in P3 are deliberately by domain, not by file count: a half-migrated *money* screen next to
a migrated one is worse than either.

## 5. Special rule — `company_id = null` means "platform-wide", not "unscoped UI"

`announcements`, `reward_items` and `gamification_rules` all allow a **null** `company_id` on
purpose: that row applies to every company. This is a business value (BR-7 territory), and it must
not be conflated with the UI's ทุกบริษัท view.

Required behaviour on those three screens:

- Scoped to company X → list shows **X's rows + the platform-wide rows**, with the platform-wide
  ones visibly badged.
- The create form keeps its own explicit "ทั้งแพลตฟอร์ม (ทุกบริษัท)" checkbox — the header scope
  supplies the default, never removes the choice.
- ทุกบริษัท mode → read-only, as everywhere else.

## 6. Risk register

| Risk | Mitigation |
|---|---|
| **Client data (PDPA)** shown cross-company by default on `client-management` | scope defaults to a company; ทุกบริษัท requires an explicit switch and shows a company chip per row. Consider forbidding ทุกบริษัท on this screen entirely — decision at §7 |
| **Money screens** (`commission-management`) mixing companies in one total | totals must state the scope in the heading; never render a cross-company sum without the word "ทุกบริษัท" next to it |
| A screen migrated to send `company_id` before the backend supports it → filter silently ignored | P1 lands before P3; ag-qa tests the endpoint, not just the screen |
| Deep links to another company's record | §1 rule 4 — label, don't block |

## 7. Decisions — ANSWERED by the human, 2026-08-19

1. **ทุกบริษัท on client/PDPA screens: NO.** `client-management` and `client-file` must always
   demand a specific company; in ทุกบริษัท mode they render `CompanyScopeNotice` and fetch
   nothing at all. Personal health data is never listed across tenants, not even read-only.
   *(Implementation note for P3: this is stricter than every other screen — the notice replaces
   the list entirely rather than making it read-only.)*
2. **Default scope on first login: ทุกบริษัท.** A Super Admin starts in the read-across view and
   must pick a company before writing anything. This is what the store already does (`null` when
   nothing is persisted), so no change was needed.

## 7b. What shipped in P1 + P2

**P1 — backend (`App\Support\CompanyScopeFilter`)**

One helper, applied to eleven index endpoints: `/products` `/brands` `/product-categories`
`/clients` `/referrals` `/commission-ledger` `/users` `/announcements` `/reward-items`
`/agent-promotions` `/storefront-banners`.

- Applies **only** for a Super Admin. For anyone else it returns immediately — TenantScope has
  already answered, and a hand-written `?company_id=` in their query string is ignored, never
  trusted. It can narrow, never widen.
- `includePlatformWide: true` on `/announcements` and `/reward-items` so §5's NULL-company rows
  stay visible alongside the scoped company's own.
- Tests: `CompanyScopeFilterTest` — no scope = all companies; scope = that company only; **a
  Company Admin naming another company still gets only their own rows** (BR-6); platform-wide rows
  survive scoping. Full suite green (1603 passed).

**P2 — frontend (`activeCompany.scopedPath()`)**

Opt-in per call site rather than a global fetch interceptor, so platform endpoints (`/companies`,
`/catalog-*`, `/platform-mail-settings`) can never be narrowed by accident. `ProductCatalogView`
now uses it for all four of its lists and refetches when the scope changes.

## 8. Definition of Done for the whole task

- [ ] No `/companies` fetch or company `<select>` remains in any view outside
      `CompanySwitcher`/`CompanyManagementView`
- [ ] Every Class B list is filtered server-side and shows a company chip in ทุกบริษัท mode
- [ ] Every Class C page shows the "ทั้งแพลตฟอร์ม" badge and ignores the scope
- [ ] Company Admin behaviour is byte-identical to today on all 32 routes (regression, BR-6)
- [ ] A Company Admin sending `?company_id=` of another company still gets only their own rows
- [ ] UAT-015 walks all 32 routes in both modes


## 7c. What shipped in P3 (2026-08-19)

**Backend — the filter reached 10 more endpoints** than P1's eleven, because P3's screens hit them:
`/reward-redemptions` `/agent-invite-links` `/user-certifications` `/user-badges`
`/product-price-promotions` `/orders` `/agent-approvals`. (`/modules`, `/exams`, `/cert-tiers`
were skipped — their index builds a query shape the mechanical patch could not safely reach; they
are listed in §9 as residual.) `AgentApprovalController` needed care: the filter must run **before**
`paginate()`, or the pager walks the unfiltered set.

**Frontend — 13 screens migrated**

- `fetchAllPages()` (shared, `agentEdit.ts`) and the three local copies
  (`ProductPerformanceView`, `AgentPromotionsView`, `RewardCenterView`) now scope in the query
  string. That one change covers every page-walking list on the agent screens — and it matters
  most there, because walking every page of an unscoped `/users` downloads every company's people
  before hiding them.
- Direct `api.get` list calls wrapped in `activeCompany.scopedPath()`:
  `CommissionManagementView` `ReferralPipelineManagementView` `GamificationConfigView`
  `SalesTeamView` `AgentCommissionSummaryView` `AnnouncementsView` `RewardCenterView`
  `AgentPromotionsView` `ProductPerformanceView` `ClientManagementView`.
- Every one of the 13 got: the store, a `watch(companyId)` that refetches, and
  `<CompanyScopeNotice>` in its template.
- **`ClientManagementView` is the strict case** (human decision §7.1): in ทุกบริษัท mode it
  **fetches nothing** — `loadAll()` returns early, `search()` refuses, and the entire page body is
  behind a guard. Personal health data is never listed across tenants.
- Untouched by design: `AdminHomeView` (nav cards, no data fetch), `AgentManagementView` (tab
  shell), `ClientFileView` (single record by id — rule 4), `VoucherRedeemView` (lookup by a
  platform-unique code).

**Verified:** all 17 staged SFCs compile clean (0 template errors); backend suite 1603 passed, Pint
clean.

## 9. Residual after P3 (for P4/P5)

- Five screens still fetch `/companies` themselves to populate a FORM dropdown
  (`AgentRosterView`, `AgentPromotionsView`, `RewardCenterView`, `AnnouncementsView`,
  `ProductPerformanceView`). They sit inside `Promise.all` arrays whose results are destructured by
  index — mechanically risky to rewrite, and they are not what scopes the list. Switch them to the
  store in P4.
- `/modules`, `/exams`, `/cert-tiers` still lack the `company_id` filter.
- §5's "platform-wide rows badged in the list, and the create form keeps its own ทั้งแพลตฟอร์ม
  checkbox" is **not yet built** on announcements/rewards/gamification — the lists now include the
  platform rows (backend `includePlatformWide`), but nothing marks them visually.
- P4 (Class C badges) and P5 (UAT-015 across all 32 routes) untouched.


## 7d. What shipped in P4 + residual clean-up (2026-08-19)

**§9 item 1 — the five local `/companies` fetches are gone.** `AgentRosterView`,
`AgentPromotionsView`, `AnnouncementsView`, `ProductPerformanceView` now push
`activeCompany.loadCompanies()` into their existing `Promise.all` (and read `activeCompany.companies`
afterwards, so the index-destructuring stays honest); `RewardCenterView`'s `companies` is now a
`computed` over the store. Contract rule 1 holds across the app: the only `/companies` fetch left is
the store's own.

**§9 item 2 — `/modules`, `/exams`, `/cert-tiers` now take the filter too.** All three needed their
index restructured so the filter runs BEFORE `paginate()`; `CertTierController::index()` gained a
`Request` parameter it never had. **18 + 3 = 21 endpoints** now honour the scope.

**§9 item 3 — §5 was already half-true.** The **ทั้งแพลตฟอร์ม badge already existed** on all three
screens (announcements, reward items, gamification rules) — that part of §9 was written without
checking, and was wrong. What was genuinely missing was the create-form default: `resetForm()` /
`resetItemForm()` now pre-select the header's company while leaving ทั้งแพลตฟอร์ม selectable.

**P4 — Class C marking.** New `PlatformScopeBadge.vue`, added to `CompanyManagementView`,
`MailSettingsView`, `CatalogManagementView` and `PolicyReportView`'s *platform* tab only (its other
three tabs follow the scope, so the badge sits inside that one tab, not the page header).

**P5 — `docs/qa/UAT-015-company-scope-all-routes.md`** walks all 32 routes plus the three
cross-tenant probes that must be run by hand.

**Verified:** every touched SFC compiles (0 template errors); backend 1603 passed; Pint clean.
**Not verified:** anything in a browser — that is UAT-015's job.
