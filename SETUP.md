# Local Setup — Sync Vision Agent

Scaffolded by ag-lead. Files are in place; a few steps need to run on
your Mac (Composer/PHP aren't available in my sandbox). See ADR-001,
ADR-002, ADR-003 in `/docs/adr/` for the reasoning behind these choices.

**Note:** if you already ran `composer install` once and hit a
"security advisories" error blocking `laravel/framework` — that's
expected on the earlier scaffold. Laravel 11 is EOL (no security
patches since 2026-03-12), so Composer refuses to install any 11.x
release. The project has been bumped to **Laravel 12** (ADR-002); pull
the latest files here and re-run `composer install`.

## 1. Backend (Laravel API)

```bash
cd backend
composer install
php artisan key:generate
```

Create the database in phpMyAdmin (or the MySQL CLI): a schema named
**`sync_vision_agent`**, no tables needed yet — migrations will create
them. `backend/.env` is already pointed at MAMP's default MySQL
(127.0.0.1:8889, root/root) — adjust if your MAMP uses different
credentials.

```bash
php artisan migrate --seed
php artisan serve --port=8010
```

API will be at `http://localhost:8010`, health check at `/up`, a test
route at `/api/v1/ping`. (Moved off the default 8000 — that port is a
common squatting target for other local tools/dev servers and kept
causing "Address already in use" on this machine.)

`--seed` creates the multi-tenant foundation from
`docs/tasks/TASK-001-multitenant-foundation.md`: one company (Thai
Life) and one dev-only user per role, all with password `password`
(Laravel's default factory password — local dev only, never use this
pattern anywhere real):

| Role | Email | Which app to log into |
|---|---|---|
| Super Admin | superadmin@example.test | Admin app (Section 3) |
| Company Admin (Thai Life) | admin@thailife.test | Admin app (Section 3) |
| Agent (Thai Life) | agent@thailife.test | Agent Portal (Section 2) |
| Agent (Thai Life) | niran@thailife.test | Agent Portal (Section 2) |
| Agent (Thai Life) | pim@thailife.test | Agent Portal (Section 2) |

If you already ran `migrate` before this update, run
`php artisan migrate:fresh --seed` to pick up the new `companies`
table and `users.company_id`/`role` columns (drops and recreates all
tables — fine pre-launch, don't use once there's real data).

**Full demo data (2026-07-12):** `--seed` now also runs
`DemoActivitySeeder`, which walks `agent@thailife.test` (plus the two
extra agents above, added so Leaderboard has real competition to show)
through real Academy/Referral/Pipeline/Commission/Badge activity via
the actual Services — not fixture rows inserted directly — so every
screen has non-empty data instead of empty states: both agents pass
Basic certification (agent@thailife.test also passes Intermediate),
each has 1-3 referrals sitting at different Pipeline stages (one fully
through to a paid Commission Ledger entry, one to a pending one, one
left mid-pipeline), and "certified_agent"/"first_sale" badges are
awarded. If you've already run `db:seed` before this update, just run
it again (`php artisan db:seed`) — everything here is idempotent, safe
to rerun, and only fills in what's missing. Log in as
`agent@thailife.test` to see the richest version of this data.

## 2. Agent Portal (Vue 3 SPA)

```bash
cd frontend
npm install
npm run dev
```

Visit at **`http://agent.localhost:5178`** — not bare `localhost:5178`
(bug fix, 2026-08-02: see "Session collision" note below). `*.localhost`
resolves to `127.0.0.1` automatically in modern browsers/OS (RFC 6761),
no `/etc/hosts` edit needed on macOS. Pinned to port 5178
(`vite.config.ts` sets `strictPort: true` — it'll fail loudly instead
of silently hopping to another port if 5178 is taken, which used to
break login in a confusing way; `allowedHosts: ['agent.localhost']` is
also set so Vite accepts the new hostname). Moved off the Vite default
5173 (human's choice: 5178) because that port was already in use by an
unrelated project on this machine — if you ever see this app land on a
different port than 5178, it means a stale `npm run dev` process is
holding 5178, or a leftover process from an older config is running on
5173-5177/5273-5275 (see the note at the bottom of this file); kill it
and restart rather than trusting whatever port shows up. Calls the API
via `VITE_API_BASE_URL` in `frontend/.env` — **also**
`agent.localhost`, just port `8010` (`http://agent.localhost:8010`),
not bare `localhost:8010`.

**Session collision bug fix (2026-08-02):** this app and the Admin app
used to both be visited at bare `localhost:<port>` and both call the
API at bare `localhost:8010`. Browser cookies aren't port-scoped, so
both apps' Sanctum session cookie was the SAME cookie — logging into
one silently logged the other's already-open tab into the same
identity too. Routing each app's page AND its API calls through its
own hostname (`agent.localhost` vs `admin.localhost`) plus leaving
`SESSION_DOMAIN` unset in the backend `.env` (host-only cookie) fixes
this — the two apps now have genuinely independent sessions, even open
in the same browser at the same time.

Log in as `agent@thailife.test` (Section 1). You should land on "My
Day" with the top nav showing SWS Referral / Pipeline / Academy /
Commission / Leaderboard. Every page is a real screen now (not a
"coming soon" placeholder) but shows placeholder `—` KPIs and disabled
create actions — each still needs its own task spec + backend
endpoints before ag-dev wires real data in (see the bottom of this
file for what's outstanding).

company_admin/super_admin users get an "Admin" link in the top-right of
the nav bar that opens the separate Admin app in a new tab (Section 3)
— Admin is no longer a route inside this app (ADR-003).

## 3. Admin app (separate Vue 3 SPA — ADR-003)

```bash
cd frontend-admin
npm install
npm run dev
```

Visit at **`http://admin.localhost:5179`** — not bare `localhost:5179`
(same session-collision fix described in Section 2). Pinned to port
5179 (same `strictPort` reasoning as above, also moved off the Vite
default to stay clear of the unrelated project on 5173;
`allowedHosts: ['admin.localhost']` also set), calling the API at
`http://admin.localhost:8010` via `VITE_API_BASE_URL` in
`frontend-admin/.env`. It's a fully separate app/build from the Agent
Portal — own `package.json`, own login screen, own top bar
(`AdminNavigation.vue`, deliberately lighter than the Agent Portal's
`TopNavigation.vue` — no multi-item route nav yet, just the module
dashboard).

Log in as `admin@thailife.test` or `superadmin@example.test` (Section
1) — same Sanctum session mechanism as the Agent Portal, since both
apps share one backend. You'll see three module cards (Manage agents /
Commission rules / Gamification rules), all inert "เร็วๆ นี้" —
each needs its own task spec + Policies before it's real (Section 5).
Log in as `superadmin@example.test` specifically to also see the
"Manage companies" card — that one's gated on the real
`auth_store.user.role`, not a guess, per Section 5 of CLAUDE.md
(company_admin sees only their own company; super_admin sees across
companies).

`design-system/` components shared by both apps (`Icon.vue`,
`AppLogo.vue`, `HeroHeader.vue`, `EmptyState.vue`) are duplicated
between `/frontend` and `/frontend-admin`, not shared via a package —
see ADR-003's "Consequences" for why, and keep both copies in sync
when changing CI-001/CI-002 decisions (colors, shapes, logo).

## 4. Design system (ported from your medical-saas / SyncVision B2B UI)

Tailwind CSS is installed in both `/frontend` and `/frontend-admin`
(v3, `tailwind.config.js` in each — keep them in sync), with the
Kanit/Noto Sans Thai font loaded in `src/assets/main.css`. The brand
colors (`brand` navy / `gold`) come from CI-002 — sampled from the
GENESENN co-brand logo, see `docs/design/CI-002-genesenn-brand.md`.
Shape language is `rounded-xl`/`rounded-2xl` everywhere except the
Login screens, which use `rounded-full` pills per CI-001's addendum.

`src/design-system/` has ported, framework-agnostic components from
your reference UI: `Icon.vue`, `HeroHeader.vue`, `TabFilterBar.vue` (Agent
Portal only — Admin doesn't need tab filtering yet), `LoadingSkeleton.vue`,
`ConfirmDialog.vue`, `EmptyState.vue` (the Apple HIG-style compact
empty-state row, medical-saas CLAUDE.md §6.3), plus
`src/composables/useI18n.js` / `useFontSize.js`.

`NotificationBell.vue` (Agent Portal only) is a visual-only stub — no
`/api/notifications` call yet (the reference version is Bearer-token
coupled to a different auth system; ours uses Sanctum cookies).

**Not ported:** the reference app's dashboard data logic (CRM/ERP
endpoints — a different business domain than ours), its Cmd+K command
palette (hardcoded to ~80 of its own pages), and its background-theme
picker (bespoke to that app).

## 5. One-time cleanup

My sandbox can create files in this folder but can't delete them (a
permission quirk on my end, not yours). Safe to delete yourself:

```bash
# Unused Laravel skeleton leftovers — this backend is API-only
# (Blade is forbidden per CLAUDE.md Section 3)
rm -rf backend/.git_stray_unused
rm -rf backend/resources/views backend/resources/js backend/resources/css
rm -f backend/vite.config.js backend/tailwind.config.js backend/postcss.config.js backend/package.json backend/.styleci.yml

# Superseded by frontend-admin/src/views/AdminHomeView.vue (ADR-003)
rm -f frontend/src/views/AdminView.vue

# Editor backup files sed left behind while I was recoloring components
rm -f frontend/src/views/LoginView.vue.bak
rm -f frontend/src/design-system/components/TopNavigation.vue.bak
```

None of these are referenced by anything, so leaving them in place is
also harmless if you'd rather skip this step.

## 6. Login (Sanctum SPA session auth)

Backend: `POST /api/v1/login`, `POST /api/v1/logout`, `GET /api/v1/me`
(the latter two behind `auth:sanctum`) — shared by both frontends.
Each app has its own Pinia store at `src/stores/auth.ts` and router
guard in `src/router/index.ts` redirecting unauthenticated visits to
`/login`.

**Required for this to work locally:** `backend/config/cors.php`
(vendor default has `supports_credentials: false` and a wildcard
origin, which silently breaks the cross-origin cookie/CSRF handshake).
It explicitly allows `http://agent.localhost:5178` (Agent Portal) and
`http://admin.localhost:5179` (Admin app), plus — local only,
`APP_ENV=local` — a regex fallback for any `<sub>.localhost:<port>` /
`localhost:<port>` / `127.0.0.1:<port>` as a safety net. Both apps now
pin their dev server port with `strictPort: true`, so the port-hopping
issue that originally motivated the regex fallback shouldn't recur, but
it's left in as a margin. `SANCTUM_STATEFUL_DOMAINS` in `backend/.env`
lists the literal hostname:port for both apps (Sanctum's own check
isn't pattern-based). If you deploy either frontend to a different
origin, add it to `allowed_origins` there — never widen it to `'*'`
while `supports_credentials` stays `true`.

**Session collision bug fix (2026-08-02):** both apps used to be
visited at bare `localhost:<port>` and both called the API at bare
`localhost:8010`. Browser cookies aren't port-scoped — only
domain+path — so the Sanctum session cookie set while using one app was
the exact same cookie the other app's already-open tab would present on
its next request, silently swapping which user that tab was acting as.
Fix: each app's page AND its API calls now go through its own hostname
(`agent.localhost` / `admin.localhost` — see Sections 2 and 3), and
`SESSION_DOMAIN` in `backend/.env` is left unset (host-only cookie,
scoped to whichever exact hostname the request hit) instead of hardcoded
to `localhost`. The two apps' sessions are now genuinely independent,
even open in the same browser at the same time. If you ever see one
app's login silently swap to the other's user again, check that
`SESSION_DOMAIN` hasn't been set back to a shared value and that both
`.env` files' `VITE_API_BASE_URL` still point at their own hostname
(not bare `localhost:8010`).

Manual check: `php artisan serve --port=8010`, then in one terminal
`cd frontend && npm run dev` (visit `http://agent.localhost:5178/`), in
another `cd frontend-admin && npm run dev` (visit
`http://admin.localhost:5179/`). Both should redirect to their own
`/login`, accept the seeded credentials (Section 1), and let you log
out back to `/login`. Worth testing both open at once in the same
browser now — logging into one should no longer swap the other's
already-open tab to the same user (the exact bug this fix addresses).

## 7. Git

No repo initialized yet. When ready:

```bash
git init
git add -A
git commit -m "chore: scaffold Laravel 12 + two Vue 3 SPAs"
```

## What's scaffolded vs. what's next

**Done:** Laravel 12 skeleton (PHP 8.3, Sanctum wired for SPA cookie
auth), two independent Vue 3 + TypeScript + Router + Pinia +
Tailwind apps (`/frontend` Agent Portal, `/frontend-admin` Admin —
ADR-003), folder structure per CLAUDE.md Section 7, `routes/api.php`
under `/api/v1`, the multi-tenant foundation (`companies` table,
`users.company_id`/`role`, `TenantScope`, `CompanyPolicy`, dev
seeder), Sanctum SPA login/logout/me end-to-end for both apps, a
full set of Agent Portal workspace screens (My Day, SWS Referral,
Pipeline, Academy, Commission, Leaderboard) plus an Admin module
dashboard — all UI shells with placeholder data — and, as of
2026-07-09, the **full database schema** from
`docs/design/ERD-001-full-schema-proposal.md` (rev. 3): 21 migrations
+ Eloquent models + 5 enums covering Product catalog (brands,
categories, products, commission rules), Academy (cert tiers, modules,
exams, certifications), Customer (clients, client documents), Referral
& Pipeline, Commission Ledger, Gamification (XP/levels/badges), and
Audit Log. Run `php artisan migrate` (Section 1) to apply them.

**Also done — Product Catalog and Academy are now full working
features** (see `docs/tasks/TASK-002-product-catalog.md` and
`TASK-003-academy.md`): Policies, Form Requests, Services, API
Resources, Controllers, `/api/v1` routes, and seed data for
Brand/ProductCategory/Product/CommissionRule and
CertTier/Module/ModuleCompletion/Exam/ExamAttempt/UserCertification.
`php artisan migrate --seed` now seeds the Phase 1 catalog data plus
3 exams + 2 modules (placeholder syllabus content, BR-7). BR-1's
access gate is real: `ExamAttemptService` computes pass/fail
server-side and `User::hasPassedCertTier()` is what any future
Referral/Pipeline check should call. The Admin app has real
**Product catalog** and **Academy** screens; the Agent Portal's
**Academy** screen is wired to real data (mark modules complete,
submit exam scores, see cert status) — neither is a "เร็วๆ นี้" card
anymore. Feature tests exist at `tests/Feature/Catalog/` and
`tests/Feature/Academy/` — Catalog's have been run and pass (after two
real bugs surfaced and got fixed: a Laravel 11+ `authorizeResource()`/
`middleware()` gotcha, and a non-idempotent seeder crashing on rerun —
both fixed project-wide, see TASK-002). Academy's tests were run once
by the human and caught a real bug (`Exam` and 3 other Academy models
were missing a `company(): BelongsTo` relation, breaking
`Model::factory()->for($company)`) — fixed, and the same sweep
proactively fixed the identical latent bug on 6 more not-yet-built
models before they could cause the same failure later. **Re-run
`php artisan test --filter=Academy` to confirm the fix actually
resolves it** — that confirmation hasn't come back yet. See
`docs/qa/UAT-001-product-catalog.md` and `UAT-002-academy.md`.

**Also done — Customer is now a full working feature** (see
`docs/tasks/TASK-004-customer.md`): Policies, Form Requests, Services,
API Resources, Controllers, `/api/v1` routes, and seed data for
Client/ClientDocument. This is the first domain where an Agent's
visibility is narrowed to only their own records (Section 5 rule 4) —
every other domain so far was company-wide readable for Agent. PDPA
protections: `health_notes` is encrypted at rest, `consent_given_at`
captures consent, and uploaded documents are stored on the private
`local` disk under a `{company_id}/{client_id}/` path, served only
through an authenticated download route (never a public URL). The
Agent Portal has a real **ลูกค้า (Clients)** screen (list, add client,
detail drawer with document upload/download) — there's no Admin-side
Clients screen yet (Company Admin can still reach every client via the
API; see TASK-004's "Out of scope"). Feature tests exist at
`tests/Feature/Customer/` — written and structurally reviewed (one
real gap found and fixed: an Agent's spoofed `referring_agent_id` was
silently discarded downstream instead of being rejected at
validation) but **not yet run by the human** — do that before trusting
this phase. The frontend's `eslint`/`vue-tsc`/`vite build` all now pass
cleanly (an earlier `Bus error` during `vite build` this session turned
out to be the sandbox's disk running low, not a code defect — resolved
and confirmed). See `docs/qa/UAT-003-customer.md`.

**Also done — a shared UI transition system** across both frontends
(see `docs/tasks/TASK-005-ui-animation.md`, not tied to any BR/phase —
a cross-cutting polish pass requested directly by the human, done now
rather than after every remaining phase so Phase 4-7 inherit it for
free): route-level page fade (`App.vue` in both apps), list-entrance
animation and a first-load skeleton (`LoadingSkeleton.vue` — ported to
`frontend-admin`, which had none before) on the Clients/Academy/Admin
Academy/Product Catalog screens, and a slide-in detail drawer on
Clients. Respects `prefers-reduced-motion`. No animation library added
— plain Vue `<Transition>`/`<TransitionGroup>` + CSS. Both frontends'
`eslint`, `vue-tsc --build`, and `vite build` all confirmed passing
after this change.

**Also done — Referral & Pipeline is now a full working feature** (see
`docs/tasks/TASK-006-referral-pipeline.md`): Policies, Form Requests,
Services, API Resources, Controllers, `/api/v1` routes, and seed data
for Referral/PipelineStageLog. `ReferralService::create()` enforces
BR-1 against the *resolved* referring agent (not just whoever's
submitting), so a Company Admin can't bypass the Basic-cert gate by
submitting on an uncertified agent's behalf either. `PipelineService::advance()`
implements CLAUDE.md §4.3's sequential-only state machine by never
accepting a target stage from the client at all — it always computes
the one allowed next stage itself — with a full audit trail
(`pipeline_stage_logs`, who/when/from→to) for every transition
including the initial one at submission. The Agent Portal's **SWS
Referral** and **Pipeline** screens are both wired to real data
(BR-1-gated submission form, tab-filtered tracking board, one-click
stage advancement, slide-in audit-trail drawer) — neither is a
placeholder shell anymore. Feature tests exist at
`tests/Feature/Referral/` (16 tests) — structurally reviewed twice
(independent subagent + direct ag-lead re-read of the highest-risk
files), zero bugs found either time, but **not yet run by the
human** — every phase so far has had at least one real bug only
surface once `php artisan test` actually ran, so treat this the same
way before trusting it. See `docs/qa/UAT-004-referral-pipeline.md`.
Commission Ledger creation at the Complete Payment stage (BR-4) is
explicitly NOT part of this phase — a `// TODO: Phase 5` marks where
it will hook in.

**Also done — Commission Ledger is now a full working feature** (see
`docs/tasks/TASK-007-commission-ledger.md`): that `// TODO: Phase 5`
marker in `PipelineService` is now real. The moment a referral's
pipeline stage becomes Complete Payment, `CommissionService` looks up
the referring agent's HIGHEST passed cert tier (new
`User::highestPassedCertTier()`), finds the matching active
`commission_rules` row for that tier x the referral's product, and
writes an immutable `commission_ledger` row — idempotent both in code
and via a new DB unique constraint on `referral_id`, and non-blocking
(if no rule/cert tier is found, it logs a warning and skips rather
than failing the pipeline transition or guessing a number). All
arithmetic is integer satang throughout (BR-3) — verified by hand:
890,000 satang x 300 basis points / 10,000 = 26,700 satang, exactly
what the tests assert. The Agent Portal's **Commission** screen is now
a real read-only ledger (own earnings only, KPIs for this month/
pending/paid) instead of a placeholder. Marking a commission "paid" is
a Company Admin/Super Admin-only API action
(`POST /commission-ledger/{id}/mark-paid`) with no Agent Portal or
Admin UI exposure yet — Agent can never mark their own commission paid
(an obvious self-dealing gap otherwise). Feature tests exist at
`tests/Feature/Commission/` (12 tests) — structurally reviewed once
(10/10 checks passed, particular scrutiny on the money arithmetic),
but **not yet run by the human** — treat this the same as every prior
phase, with extra caution given this is the first phase computing real
money amounts. See `docs/qa/UAT-005-commission-ledger.md`.

**Also done — Gamification (XP, Level, Badge, Leaderboard) is now a
full working feature** (see `docs/tasks/TASK-008-gamification.md`): the
4 XP-insertion points anticipated by earlier phases' Services
(`ModuleCompletionService`, `ExamAttemptService`, `ReferralService`,
`PipelineService`) now actually call `GamificationService::awardXp()`,
which resolves the applicable rate from `gamification_rules` (a
company-specific row overrides the platform-wide default when both
exist) and is non-blocking by design (missing config logs a warning and
skips, same failure-mode philosophy as `CommissionService`). Two
farming-prevention gates were the highest-risk part of this phase:
`ModuleCompleted` XP is gated on `ModuleCompletion`'s own idempotent
creation, but `ExamPassed` XP is deliberately gated on the resulting
`UserCertification`'s idempotent creation instead of the `ExamAttempt`
itself — since retaking/repassing an already-passed exam is explicitly
allowed by design and has no uniqueness constraint, awarding XP off
every passing attempt would be farmable. XP always credits the
resolved agent, never a Company Admin acting on their behalf. A full
`gamification_rules` CRUD API (company-override-or-platform-default,
Super-Admin-only for platform defaults), a read-only `xp_ledger` API
(no write endpoints at all), a standalone `GET /leaderboard`
(company-scoped ranking, deliberately no "Level" field anywhere — no
threshold formula/config exists, none invented), a read-only `badges`
catalog, and a `user_badges` API (own earned badges + a
Company-Admin-only manual award action, idempotent) round out the
backend. The Agent Portal's **Leaderboard** screen is now real (ranked
list, own row highlighted, earned badges) instead of a placeholder —
the old weekly/monthly/all_time tabs were removed rather than shipped
non-functional, since no server-side period filtering was built this
phase. **Academy**'s "XP จากการเรียน" KPI is now wired to real data
too. Feature tests exist at `tests/Feature/Gamification/` (34 tests
across 6 files) — structurally reviewed once (zero bugs found, third
consecutive clean structural review). **The human then actually ran
this suite and it caught two real bugs a static review couldn't**:
(1) `CommissionLedger`/`XpLedger` were both missing `protected $table`
overrides, so Eloquent guessed the pluralized table name
(`commission_ledgers`/`xp_ledgers`) instead of the actual singular
migration-created tables — every write/read on BOTH Commission Ledger
(Phase 5!) and Gamification failed with "no such table" until fixed;
(2) two tests asserted 201 on an idempotent repeat-call that Laravel
correctly returns 200 for (the test's own bug, not the app's — fixed by
asserting `assertOk()` instead). Both fixed, re-run confirmed 34/34
passing. See `docs/qa/UAT-006-gamification.md` and TASK-007/TASK-008's
own post-hoc notes.

**Also done — Admin: Manage Agents, Manage Companies, Gamification
Config** (see `docs/tasks/TASK-009-admin-management.md`): the last 3
"เร็วๆ นี้" cards on the Admin dashboard are now real. Manage Companies
(Super Admin only) is plain CRUD over the pre-existing `Company` model.
Manage Agents lets a Company Admin manage their own team (agent +
company_admin roles, never super_admin, verified rejected on both
create AND update paths) — 3 scope decisions were confirmed with the
human first rather than guessed (no email/invite system exists, so an
Admin types a temporary password directly; role can be changed between
agent/company_admin within one's own company; "deleting" an agent is a
soft-delete/deactivate, reversible via restore). Gamification Config is
pure frontend — the API already existed from Phase 6. Structural review
caught and fixed one Section 7 layering issue
(`CompanyController::destroy()` bypassing its Service) and one test
coverage gap, and specifically re-checked for the exact `$table` bug
class above — confirmed it doesn't recur here. Feature tests exist at
`tests/Feature/Platform/` (22 tests) — **not yet run by the human**,
same caveat as always, now doubly warranted given what the last run
caught. See `docs/qa/UAT-007-admin-management.md`.

**Also done — Admin: Clients, Referral & Pipeline, Commission oversight
screens** (see `docs/tasks/TASK-010-admin-oversight-screens.md`): the
very last "unbuilt UI over an already-working API" gap is closed. Pure
`frontend-admin` work, zero backend changes — `ClientManagementView.vue`
(company-wide read-only client list + document viewing, no create/
upload — that stays Agent-initiated), `ReferralPipelineManagementView.vue`
(company-wide referral list, advance action, audit-trail drawer),
`CommissionManagementView.vue` (company-wide ledger + the "จ่ายแล้ว"
mark-paid action, already Policy-restricted to Company Admin/Super
Admin since Phase 5). `frontend-admin/src/api/client.ts` gained a
`download()` helper it never had before. `eslint`/`vue-tsc build`/
`vite build` all confirmed clean. No new backend structural review was
needed (nothing backend changed) — every endpoint used here already has
its own review/test history from Phases 3-5. See
`docs/qa/UAT-008-admin-oversight-screens.md`.

**Also done — Level system, Badge auto-award, Move user between
companies** (human-confirmed decisions, see
`docs/tasks/TASK-011-level-system.md`,
`docs/tasks/TASK-012-badge-auto-award.md`,
`docs/tasks/TASK-013-move-user-company.md`): the three remaining items
that were previously listed below as "genuine business-decision gaps"
are now built, each gated on an explicit human answer rather than a
guess.

- **Level** (TASK-011): `level_thresholds` (schema existed, unused since
  Phase 6) now has a full CRUD API (`LevelThresholdController`,
  Super-Admin-write/anyone-read — platform-wide, no per-company
  override) and a `LevelService` that computes XP->Level for
  `/leaderboard` and both frontends. Seeded with a placeholder 10-level
  curve, `TODO: CONFIRM (BR-7)`. Admin config UI: new "Level" tab in
  `GamificationConfigView.vue`.
- **Badge auto-award** (TASK-012, closes ERD-001 open question #9):
  `BadgeConditionEvaluator` — a whitelisted 3-metric/5-operator
  interpreter (AND-only, fails closed on anything it doesn't recognize)
  — plus `BadgeAutoAwardService`, hooked into
  `GamificationService::awardXp()` (the single funnel already used by
  every XP-triggering Service). Badge authoring (create/update/delete,
  including `condition_config`) is now possible via the API and the
  Admin "Badge" tab — previously badges were seed-only/index-only.
  Manual awarding still works unchanged.
- **Move user between companies** (TASK-013): `POST
  /users/{user}/move-company`, Super-Admin-only
  (`UserPolicy::move()`), writes an `AuditLog` row and updates
  `company_id` inside one DB transaction. Commission/XP ledger history
  is untouched (both already store their own independent `company_id`
  captured at write time) — moving a user only affects what they can
  see/do going forward. Admin UI: "ย้ายบริษัท" action in
  `AgentManagementView.vue`.

All three: backend feature tests written (`LevelThresholdTest`,
`BadgeCrudTest`, `BadgeConditionEvaluatorTest`, `BadgeAutoAwardTest`,
`MoveUserCompanyTest` — not yet run by the human, same caveat as
always), a subagent structural review found no blocking bugs or
security gaps (two minor hardening fixes applied: `moveToCompany()`
wrapped in `DB::transaction()`, `LevelService` memoized to avoid an
N+1 across leaderboard rows), and `eslint`/`vue-tsc --build`/`vite
build` confirmed clean for both `frontend` and `frontend-admin`. See
`docs/qa/UAT-009-level-system.md`, `docs/qa/UAT-010-badge-auto-award.md`,
`docs/qa/UAT-011-move-user-company.md`.

**Post-hoc bug fixes found during the human's first real end-to-end
QA pass** (running `php artisan test` plus actually clicking through
both frontends — this is what finally surfaced these; none were caught
by structural review since all 3 are runtime/session-state issues):

1. **Referral stage-log ordering** (`ReferralController::stageLogs()`):
   sorted `orderByDesc('changed_at')` with no tie-breaker.
   `changed_at` only has second-level precision, so two stage changes
   within the same second (routine in tests, possible in fast real
   usage) could come back in an undefined order. Fixed by adding
   `->orderByDesc('id')`. A companion test-only bug was fixed too:
   `PipelineTest::makeCertifiedAgentReferral()` built its Referral via
   raw `Referral::create()` instead of `ReferralService::create()`,
   silently skipping the initial "creation" `PipelineStageLog` row the
   real path always writes — fixed by adding that row to the test
   helper. See `docs/tasks/TASK-006-referral-pipeline.md`.
2. **Leaderboard 422 for Super Admin** (`LeaderboardView.vue`, Agent
   Portal): this screen has no company picker, but Super Admin has no
   single "own company" — `/leaderboard` always 422'd asking for one,
   showing a raw HTTP error. Fixed by skipping the call entirely for
   Super Admin and showing a plain-language explanation ("use the
   Admin app instead") — Super Admin was never meant to use this
   screen in the first place.
3. **Stale session shows raw 401s instead of returning to login**
   (`api/client.ts` + `main.ts`, both `frontend` and `frontend-admin`):
   the router guard only checks auth ONCE per page load, so a session
   that expires mid-use (not "never logged in") left every subsequent
   API call 401ing with the SPA stuck showing stale content. Fixed
   with a global `setUnauthorizedHandler()` hook in the API client,
   wired at boot to clear the stale user and redirect to `/login` on
   any 401 (except `/me`'s own routine "am I logged in" check).
4. **Unauthenticated API request crashed with a 500, not a clean 401**
   (`bootstrap/app.php`): CLAUDE.md Section 3 — "strictly a RESTful
   API ... Blade forbidden" — but `withExceptions()` was empty, so
   Laravel's *default* unauthenticated handler tried to redirect
   non-JSON-expecting requests (e.g. hitting an API URL directly in a
   browser, which sends `Accept: text/html`) to a named `login` route
   that doesn't exist anywhere in this app (no web login page, only
   the SPA) — `RouteNotFoundException`, unhandled 500. This one took
   **three** attempts — worth recording all three so the same trap
   doesn't get re-walked-into later:
   - **Attempt 1 (wrong)**: registering
     `$exceptions->render(fn (AuthenticationException $e, ...) => ...)`.
     Never ran — Laravel's Handler special-cases `AuthenticationException`
     and calls its own `unauthenticated()` method directly, *before* any
     custom `render()` closures are consulted.
   - **Attempt 2 (wrong)**: `$exceptions->shouldRenderJsonWhen()` alone.
     This looked like the right hook — and it IS one half of the real
     fix — but it lives in the exception *Handler*, which is never
     reached for this crash. The actual crash happens earlier, inside
     `Illuminate\Auth\Middleware\Authenticate::unauthenticated()`, while
     it's still *constructing* the `AuthenticationException`: for a
     non-JSON-expecting request it eagerly calls a registered
     `redirectTo` callback, and `route('login')` blows up right there —
     before the exception is even thrown, let alone handled.
   - **Attempt 3 (correct)** — root cause traced by reading
     `vendor/laravel/framework` directly:
     `Illuminate\Foundation\Configuration\ApplicationBuilder::withMiddleware()`
     unconditionally defaults to
     `redirectGuestsTo(fn () => route('login'))` *before* this app's own
     `withMiddleware()` callback runs, and this app never overrode it.
     Fix has two parts, both needed: `$middleware->redirectGuestsTo(fn
     () => null)` in `withMiddleware()` (stops the crash at its actual
     source, inside the middleware) plus keeping
     `$exceptions->shouldRenderJsonWhen()` in `withExceptions()` as
     defense-in-depth (the Handler's `unauthenticated()` has its own
     second `?? route('login')` fallback that this closes off too). Both
     live in `bootstrap/app.php` now, each fully commented with this
     reasoning. Test: `tests/Feature/Auth/UnauthenticatedRequestTest.php`
     (specifically uses a plain, non-`getJson()` request to reproduce the
     exact `Accept: text/html` path that triggered the original bug — a
     `getJson()`-only test would never have caught this). **Confirmed
     live via browser** (Claude-in-Chrome, not `php artisan test` — no
     PHP in my sandbox): navigating directly to
     `http://localhost:8010/api/v1/leaderboard?company_id=1` with no
     session now returns a clean `401 {"message":"Unauthenticated."}`
     instead of a 500. **Still worth running
     `php artisan test --filter=UnauthenticatedRequestTest` (and the
     full suite) yourself** for the PHPUnit-level confirmation.

None of these needed a business decision — all are either genuine
runtime bugs or missing defensive UX, fixed directly.

**Port conflict found and fixed (2026-07-12):** the human's machine
already runs a different, unrelated project on `localhost:5173`, so
this project's frontends have moved off Vite's default. Per the
human's own choice, `frontend` is pinned to **5178** and
`frontend-admin` to **5179** (both still `strictPort: true`). Updated
everywhere this port was referenced: both `vite.config.ts` files,
`backend/.env` (`SANCTUM_STATEFUL_DOMAINS`, `FRONTEND_URL`,
`ADMIN_FRONTEND_URL`), `backend/config/cors.php`, `frontend/.env`'s
`VITE_ADMIN_APP_URL`, and `TopNavigation.vue`'s fallback URL.

**Important — 5178 is also where the old ghost process lives:** this
session separately found a genuinely **ancient** leftover `npm run
dev` process already squatting on port 5178 — opening it in the
browser showed the ORIGINAL scaffold placeholder screen ("รอ endpoint
จาก ag-dev"), predating every phase built in this project, not just
the recent fixes. That's what was actually causing the Leaderboard 422
screenshots — it was never running current code at all. Since 5178 is
now the app's real pinned port too, **you must kill that old process
before starting the real one**, otherwise `strictPort: true` will make
the real `npm run dev` fail outright (port already in use) rather than
silently serving stale content — which is a smaller, louder-failing
version of the same problem, so treat it as a required first step, not
optional cleanup:

```
lsof -ti:5178,:5179,:5173,:5273,:5275 | xargs kill -9   # kill anything old holding these ports
cd frontend && npm run dev        # should now bind cleanly to 5178
cd frontend-admin && npm run dev  # should now bind cleanly to 5179
```

If `npm run dev` still fails with "port already in use" after that,
something is still holding it — re-run `lsof -i :5178` to find the PID
and kill it before trusting anything you see in the browser at that
URL.

**5. `auth.user.role`/`.id`/`.name`/`.company` were silently
`undefined` everywhere in BOTH frontends — the real reason the
Leaderboard fix "didn't work" even on a fresh server.** Found by
testing live in the human's actual browser (Claude-in-Chrome) after
the port fix above still showed a 422: `/me` and `/login` return a
Laravel single-resource `JsonResource` (`UserResource`), which Laravel
auto-wraps as `{ "data": {...} }` — confirmed directly:
`fetch('/api/v1/me').then(r=>r.json())` returned
`{"data":{"role":"super_admin", ...}}`, not a flat object. Every other
endpoint in both frontends already knows this (every collection call
is typed `api.get<{ data: T[] }>(...)` and manually unwraps `.data`)
— `frontend/src/stores/auth.ts` and `frontend-admin/src/stores/auth.ts`
were the only two places that assigned the raw `{ data: {...} }`
envelope straight to `user.value` without unwrapping. This meant
`auth.user.role`, `.id`, `.name`, `.company` were `undefined`
everywhere they were read (Leaderboard's `isSuperAdmin` check, its own
row's `(คุณ)` highlight, TopNavigation's Admin-link-for-admins gate,
frontend-admin's per-role dashboard cards) — silently, because
`isAuthenticated` only checks `user.value !== null`, which stayed true
throughout, so login itself never looked broken. Fixed by unwrapping
`.data` in both `fetchUser()`/`login()` in both `auth.ts` files.
**Confirmed live**: reloading Leaderboard afterward showed the correct
"Super Admin กรุณาดูผ่าน Admin app แทน" message with no 422, and the
"Admin" link now correctly appears in the top nav (it didn't before,
same root cause). `frontend-admin`'s role-gated dashboard cards use
the identical pattern and should be spot-checked the same way, though
not yet re-verified live in that app specifically.

**6. Avatar/background upload returned 500 and 422 (2026-07-12) — not a
code bug, the migration was never run against the human's real MySQL
DB.** Reported by the human with a screenshot of `PUT /me/background`
→ 500 and `POST /me/avatar` → 422 at `localhost:5178/profile`.
Reproduced directly against the human's live backend (Claude-in-Chrome
`fetch()` calls, since APP_DEBUG error bodies are the only way to see
the real cause from outside the machine): both requests failed with
`SQLSTATE[42S22]: Column not found: 1054 Unknown column
'background_type'/'avatar_path' in 'field list'` — the
`2026_07_12_050000_add_profile_customization_to_users_table` migration
(built in the previous session) had never actually been run with
`php artisan migrate` on the human's machine, so the columns simply
don't exist yet in the real database. **Action needed from you:** run
`php artisan migrate` inside `backend/` once (also run
`php artisan storage:link` if you haven't — avatar/background image
URLs won't resolve without it). Nothing in the frontend or backend
code needed to change for this part.

**7. Self-service "edit name/surname + change password" added
(2026-07-12), plus a `users.name` architecture decision.** The human
asked to add name/surname/password editing to ProfileSettingsView.
`users.name` was a single combined column with no existing
first/last split anywhere in the app — this is an architecture
decision (not a BR-7 business value), so it was confirmed with the
human via AskUserQuestion rather than guessed: chose to split into
real `first_name`/`last_name` columns (over keeping one combined
field). Design: `name` is KEPT as a column but becomes a
derived/synced value — `User::booted()`'s new `saving()` hook
recomputes `name = trim("{first_name} {last_name}")` whenever either
is dirty — so every existing read site (`UserResource`,
`LeaderboardController`, both frontends' `auth.user.name`,
`initials()`, audit logs, `AgentManagementView`'s list display, etc.)
keeps working unchanged; only WRITE paths needed updating
(`StoreUserRequest`/`UpdateUserRequest` for the "Manage Agents" flow,
`UserFactory`, `DatabaseSeeder` — which uses `WithoutModelEvents` and
so sets `name` explicitly itself since the hook can't fire there).
New migration `2026_07_12_090000_split_name_into_first_last_on_users_table`
adds the columns nullable (no `->change()`/doctrine-dbal dependency)
and best-effort backfills existing rows by splitting on the first
space. New self-scoped endpoints `PUT /me/name` and `PUT /me/password`
(current-password-verified via Laravel's built-in `current_password`
validation rule) added to both `ProfileSettingsView.vue`s.
`AgentManagementView.vue`'s "เพิ่มตัวแทน" create form updated from one
`name` input to `first_name`/`last_name` (required consequence of the
`StoreUserRequest` change, not optional scope creep). **This migration
also needs `php artisan migrate` run — it's bundled with item 6 above,
one `migrate` run picks up both.**

**8. Client Management — "products of interest" + status + downloadable
sales materials (2026-07-13).** Human-requested. Clarified via 3
questions before building (ag-lead guardrail — never guess a schema/
design decision): (a) "customer status" reuses the existing Referral
Pipeline Stage rather than a new Client field (a client can have zero,
one, or several referrals — all are shown, never collapsed to one
value); (b) "select a product" reuses the existing SWS Referral flow
(`POST /referrals`) rather than a new "interest" concept — Agent
Portal's `ClientsView.vue` drawer now has an inline "+ เพิ่มสินค้าที่สนใจ"
mini-form (BR-1 gated, same as `ReferralsView.vue`) instead of
requiring nav away; (c) sales-material files are Company-Admin-uploaded
and PRIVATE (access-checked, same pattern as `ClientDocumentService`),
not public — new table `product_sales_materials` (migration
`2026_07_13_100000`), `ProductSalesMaterialService`/Controller/Resource,
routes nested under `/products/{product}/sales-materials` (index/store)
+ standalone `/sales-materials/{id}` (download/destroy). No new Policy
class — deliberately reuses `ProductPolicy::view`/`update` (view = any
same-company user including Agents, since they need to hand these to
clients; update = Company Admin/Super Admin only), since that's exactly
the visibility this feature needs. `ClientResource` gained a `referrals`
field (product + stage per row, via the existing `ReferralResource`).
Admin's `ProductCatalogView.vue` gained its first expandable/selectable
row (click a product to manage its sales materials) — a new UI pattern
for that page, since none existed before. Full feature test coverage
(`ProductSalesMaterialTest`, plus 3 new tests in `ClientTest`) and an
independent subagent structural review (tenant isolation/IDOR,
authorization, resource shape) — clean, no bugs found. **New migration
— run `php artisan migrate` (bundled with items 6/7 above).**

**Every feature explicitly requested so far is now built.** What
remains is exclusively (a) verification the human still needs to run
(`php artisan test` for every suite — this has grown across several
phases without a confirmed full run; especially worth running given
the `$table` bug found earlier this project), and (b) genuine
business-decision gaps that still must not be guessed: creating a
Super Admin account (deliberately kept manual/out-of-band only — not
revisited this round, no request to change it), and the remaining
`// TODO: CONFIRM` items in ERD-001 (health-data fields beyond the
current single `health_notes` text field, full consent flow beyond a
timestamp, exam engine shape, whether `meeting_number` has a hard cap,
real commission %/XP values and Level thresholds to replace the
current placeholders, badge condition_config real values, etc.) — none
block schema/structure, but do block seeding real config values (BR-7).
These need the human's input before any further code gets written
against them.

## ADR-007 — Product Media/Specs, Sales Sharing, Academy Video (2026-07-15)

Backend built this round (see ADR-007 for the full design/decisions).
**Two new deployment requirements, both required for video features to
actually work — the app degrades gracefully without them (uploads still
succeed, just stay uncompressed/`processing_status = failed`), but
neither is optional for the real feature to function:**

1. **`ffmpeg` must be installed on the server** and reachable on the
   system `PATH` (checked via `which ffmpeg`). Used by
   `App\Jobs\CompressUploadedVideo` (Symfony Process, no new Composer
   package) to compress uploaded video for Product media, Sales
   materials, and Academy modules, and to generate Product media
   thumbnails.
2. **A queue worker must be running** — `php artisan queue:work`
   (`QUEUE_CONNECTION=database`, already configured; the `jobs` table
   migration ships with Laravel by default). Without a worker running,
   an uploaded video sits at `processing_status = pending` forever
   (harmless, just never compresses) — same "background job needs a
   worker process" note as TASK-016/TASK-024's scheduled commands.

**New migrations — run `php artisan migrate`** (6 new files,
`2026_07_15_100000` through `2026_07_15_150000`): `video_processing_settings`,
`product_media`, `product_specs`, video/embed fields on
`product_sales_materials` (SQLite-safe rebuild, same pattern as prior
nullable-column migrations this project — MySQL untouched, raw
`MODIFY`), `sales_material_share_links`, video fields on `modules`.

**Not yet run:** `php artisan test` for the 4 new feature test files
(`ProductMediaTest`, `ProductSpecTest`, `SalesMaterialShareLinkTest`,
`ModuleVideoTest`) — ag-lead's sandbox has no PHP binary this session,
same limitation as every prior round. Please run the full suite once
after migrating.

**Frontend for both apps is now built** (previously pending, now done):
Product media gallery + specs editor and sales material video upload +
share-link UI + `video_processing_settings` config screen and Academy
module video/iframe UI, all in `/frontend-admin`'s `ProductCatalogView.vue`
and `AcademyManagementView.vue`; product gallery/specs display + share-link
generation and Academy video/iframe playback in `/frontend`'s
`ClientsView.vue` and `AcademyView.vue`. Both apps share a new
`AuthenticatedMedia.vue` component + `useAuthenticatedMedia.ts` composable
(one copy per app, per the established "duplicated design-system" pattern)
that fetch sanctum-protected stream/thumbnail URLs as a blob and render
them as `<img>`/`<video>` — a plain `<img src="...">` cannot carry the
session cookie cross-origin, so this was new plumbing this round
(`api.getBlob()` added to both `api/client.ts` files).

**Verification done:** `vue-tsc --noEmit` passes clean on both apps.
`npm run build` itself fails in ag-lead's sandbox with an unrelated
pre-existing error (`Cannot find native binding` — a rolldown/npm
optional-dependency bug, confirmed to already fail identically on the
untouched `/frontend` app before this round's changes) — please run a
real `npm run build` on your machine to confirm bundling, since the
sandbox can only type-check, not bundle.

**One scope question surfaced during review, not yet resolved (routed
to the human per CLAUDE.md §8 — not decided silently):** share-link
revoke (`DELETE /share-links/{id}`) currently authorizes via
`can('view', $salesMaterial->product)` — i.e. ANY same-company user who
can see the product (including other agents) can revoke a link, not
just the agent who created it. Both frontends currently show the revoke
button unconditionally, consistent with that backend rule as written.
Please confirm whether this is intended, or whether revoke should be
restricted to the link's own creator (or Company Admin/Super Admin
only) — see chat for the question.

**Still not run:** `php artisan test` for the 4 new feature test files
(`ProductMediaTest`, `ProductSpecTest`, `SalesMaterialShareLinkTest`,
`ModuleVideoTest`) — ag-lead's sandbox has no PHP binary this session,
same limitation as every prior round. Please run the full suite once
after migrating.

## ADR-008 — Product Spec Attachment Gallery (2026-07-17)

Backend built this round (see ADR-008 for the full design/decisions):
a new `product_spec_attachments` table + gallery, parallel to (not part
of) `product_media`, accepting image OR **PDF** uploads/embeds, plus a
new `spec_description` free-text column on `products`.

**One more deployment requirement, same "degrades gracefully without
it" shape as `ffmpeg` above — required for real PDF thumbnails/page
counts, but not optional for the feature to be usable at all:**

3. **`poppler-utils` must be installed on the server** and its
   `pdftoppm`/`pdfinfo` binaries reachable on the system `PATH`
   (checked via `which pdftoppm` / `which pdfinfo`). Used by
   `App\Jobs\GeneratePdfThumbnail` (Symfony Process, no new Composer
   package, same convention as `CompressUploadedVideo`) to render page 1
   of an uploaded spec PDF to a JPEG thumbnail and read its page count.
   If the binaries are missing or the job fails for any reason, the
   original uploaded PDF is left fully usable (streamable/viewable) —
   only the thumbnail stays absent (frontend falls back to a generic PDF
   icon) and `processing_status` flips to `failed`, logged for a human.
   Still needs the same queue worker (`php artisan queue:work`) noted
   above — without it, an uploaded PDF sits at `processing_status =
   pending` forever (harmless, just never gets a thumbnail).

**New migrations — run `php artisan migrate`** (2 new files,
`2026_07_17_100000`/`2026_07_17_110000`): `product_spec_attachments`
and `spec_description` on `products`.

**Not yet run:** `php artisan test --filter=ProductSpecAttachment` for
the new `ProductSpecAttachmentTest` feature test file — same sandbox
limitation as every prior round (see ag-dev's own run output for this
round if provided separately). Please run it, plus
`php artisan test --filter=ProductTest`, once after migrating.

## Academy Sprint 1-6 (2026-07-21) — Exam Engine, Authoring UI, Progress Dashboard, Certificate

Full rollout of the real Academy/LMS system, confirmed with you before
starting (4 clarifying questions, all answered with the recommended
option): question bank + real grading (Sprint 1), Admin exam authoring
UI incl. module edit/delete/publish (Sprints 2 &amp; 4), a real
multiple-choice quiz UI in the Agent Portal (Sprint 3, replacing the old
raw-score-entry placeholder), a per-agent × per-module Admin progress
dashboard (Sprint 5), and an on-demand certificate PDF (Sprint 6). The
BR-1 "warn before you hit the wall" UX guard on `ReferralsView.vue` and
`ClientsView.vue` was already built in an earlier round — confirmed
still in place, nothing new needed there.

**One new deployment requirement — required for the certificate
download button to work at all (no graceful-degradation path this
time, unlike ffmpeg/poppler-utils above):**

4. **Run `composer require barryvdh/laravel-dompdf` in `backend/`.**
   `composer.json` already lists `barryvdh/laravel-dompdf: ^3.1` under
   `require`, but ag-lead's sandbox has no PHP/Composer binary this
   session (same limitation noted throughout this file), so the actual
   `composer.lock` entry + vendor install could not be produced here.
   Running that command will reconcile the lock file and install it;
   Laravel 12's package auto-discovery registers its service provider
   automatically, no `config/app.php` edit needed. Used by the new
   `App\Services\Academy\CertificatePdfService` (`GET
   /user-certifications/{id}/download`) — renders a plain, neutral
   certificate (company name, agent name, cert tier name, pass date)
   from a PHP-built HTML string (deliberately not a `.blade.php` view,
   to stay unambiguously clear of CLAUDE.md §3's "Blade templating is
   strictly forbidden" rule, which targets serving HTML pages to the
   SPA — see the Service's own docblock for the full reasoning).
   **BR-7 note:** no logo/signature/seal — `Company` has no logo column
   yet, and exact certificate branding was never confirmed, so nothing
   beyond real DB fields was invented. Say the word if you want a real
   letterhead design and I'll turn it into a proper spec.

**No new migrations this round** — Sprint 6 renders on-demand from the
existing `user_certifications` row, no new column/table.

**Not yet run:** no backend feature test written for the download
endpoint this round (would need PHP to actually render a PDF and assert
on bytes/headers, which the sandbox can't do) — worth a quick
`ExamCertificateTest` (assert 200 + `content-type: application/pdf` +
403 for a different company's admin) once you have a PHP environment
handy. Both frontends verified via `vue-tsc --noEmit` + `eslint
--max-warnings=0`, zero output on every changed file.

## ADR-009 — Udemy-Style Course Hierarchy (2026-07-22)

Full redesign, confirmed with you: you sketched the desired structure
yourself (`cert tier → Module → clip → แบบทดสอบ`) and confirmed it
matches standard international LMS design, then said "ปรับระบบให้เป็น
มาตรฐาน Udemy แบ่งงานเป็น sprint ออกมา" (adjust to Udemy standard, split
into sprints) and "จัดการเลย" (go ahead) to the 6-sprint plan presented.
See ADR-009 in `/docs/adr/` for the full design.

**What changed, in one line:** `Module` (used to be one content item)
is now a "Section" (a syllabus chapter, no content of its own); the
actual video/pdf/link/quiz content now lives on a new `ModuleLesson`,
many per Section; a `content_type=quiz` Lesson can carry a small
formative self-check quiz (`module_lesson_quiz_questions`/`_options`)
that is deliberately separate from the Exam engine and never gates
BR-1.

**New migrations — run `php artisan migrate`** (5 new files,
`2026_07_22_090000` through `2026_07_22_090400`): `module_lessons`,
`module_lesson_quiz_questions`, `module_lesson_quiz_options`, the
one-time data cutover (wraps every existing `Module` row into a
Section + one Lesson carrying its original content, and retargets
`module_completions` from `module_id` to `module_lesson_id` via a full
shadow-table rebuild — no `doctrine/dbal` dependency, same convention
used for the `commission_ledger` migration earlier in this project),
and finally dropping the now-unused content columns
(`content_type`/`source_type`/`content_ref`/`processing_status`/
`xp_reward`) off `modules`. **Run these in order, on top of an
already-migrated database** — the cutover migration reads the
pre-existing `modules`/`module_completions` data, so it must run before
the column-drop migration, and both assume your existing seeded data
(2 modules from `AcademySeeder`) is still present. If you've already
run `php artisan migrate:fresh --seed` after this update, the seeder
itself now creates the new Section+Lesson shape directly — the cutover
migration will simply have nothing to convert (still runs harmlessly,
just an empty loop).

**Route changes** — `GET/POST/PUT/DELETE /modules` still exists (now
Section-only, no content fields accepted); `GET /modules/{id}/stream`
is **gone**, replaced by `POST /modules/{module}/lessons` (create),
`PUT`/`DELETE /module-lessons/{id}`, `GET
/module-lessons/{id}/stream` (video), and
`GET|POST /module-lessons/{id}/quiz-questions` +
`PUT|DELETE /module-lesson-quiz-questions/{id}` +
`POST /module-lesson-quiz-questions/{id}/options` +
`PUT|DELETE /module-lesson-quiz-options/{id}` for lesson-quiz
authoring. `POST /module-completions` now takes `module_lesson_id`,
not `module_id`.

**Frontend for both apps is rebuilt** this round: `frontend-admin`'s
"โมดูล" tab in `AcademyManagementView.vue` is now a two-level
accordion (Section CRUD, expand to manage its Lessons — video
upload/replace UI carried over unchanged from ADR-007 — expand a
`content_type=quiz` Lesson to author its quiz questions, reusing the
Exam Sprint 1 question-bank authoring UI pattern), and its "progress"
tab now rolls up completions at Lesson granularity with a per-Section
"X/Y lessons" readout. `frontend`'s `AcademyView.vue` groups Lessons
under their Section, "mark complete" targets a Lesson, and a
`content_type=quiz` Lesson exposes a "ลองทำแบบทดสอบทบทวน" self-check —
**this is intentionally ungraded** (no pass/fail, no score): the
lesson-quiz options mask `is_correct` from the Agent role exactly like
the Exam engine does, and no attempt/grading endpoint was built for
lesson quizzes this round (out of scope — see ADR-009's "Out of
scope"). If you want real graded lesson-quiz attempts later, that's a
follow-up feature to spec, not something built silently here.

**Verification done:** `vue-tsc --noEmit` and `eslint --max-warnings=0`
both pass clean on every changed file in both `frontend` and
`frontend-admin`. New/updated backend feature tests exist at
`tests/Feature/Academy/ModuleTest.php` (Section CRUD + Lesson
CRUD/tenant isolation), `tests/Feature/Academy/ModuleVideoTest.php`
(rewritten for Lesson-scoped video upload/embed/stream),
`tests/Feature/Academy/ModuleLessonQuizTest.php` (new — lesson-quiz
authoring, mutual-exclusion, `is_correct` masking, mirroring
`ExamQuestionTest.php`), and 3 updated tests in
`tests/Feature/Gamification/XpAwardingTest.php` (module-completion XP
trigger, now posting `module_lesson_id`). **Not yet run** — same
sandbox limitation as every prior round (no PHP binary here). Please
run `php artisan test --filter=Academy` and
`php artisan test --filter=XpAwarding` once you've migrated.
`database/factories/ModuleFactory.php` was trimmed to Section-only
fields, `database/factories/ModuleLessonFactory.php` is new (carries
the old content-item factory shape), and
`database/factories/ModuleCompletionFactory.php` +
`database/seeders/AcademySeeder.php` +
`database/seeders/DemoActivitySeeder.php` were all updated for the new
shape — re-run `php artisan db:seed` (idempotent, same as always) to
confirm the 2 existing modules convert to Section+Lesson cleanly.

## ADR-028 / TASK-142+143+146 (2026-08-08) — Academy lesson files, HTTP Range streaming, verified progress

Backend for the whole sprint (see ADR-028 for the design and the human
decisions behind it). Three things changed that affect deployment.

### 1. Run the migrations

`php artisan migrate` — 3 new files (`2026_08_20_090000`/`090100`/`090200`):

- `module_lessons` gains `is_downloadable` (bool, default false),
  `duration_seconds` and `page_count` (both nullable, both
  **server-measured, never client-supplied**).
- `module_lesson_progress` — one row per learner per lesson.
- `academy_completion_settings` — per-company thresholds (BR-7), seeded
  with the human's stated defaults: video **80%**, PDF **100%**.

### 2. One more optional binary, same "degrades gracefully" shape as `ffmpeg`

4. **`ffprobe`** (ships with `ffmpeg`, so if requirement 1 above is met
   this almost certainly already is) reachable on `PATH`, or set
   `FFPROBE_PATH` in `.env`. `CompressUploadedVideo` uses it to record a
   lesson video's real duration, which is the **denominator of the video
   completion gate**.

   **Read this before rollout — it is a deliberate fail-open, not a
   bug:** if `ffprobe` is unavailable, `duration_seconds` stays null,
   and `LessonCompletionGate` then treats that lesson as "not
   verifiable" and lets the plain mark-complete button through. Failing
   the other way would make every video lesson uncompletable and block
   the BR-1 certification path for a whole company because of an
   infrastructure gap on our side (ADR-028 §5 R1). The visible symptom
   of a missing `ffprobe` is therefore a *weaker* gate, not an error —
   check `module_lessons.duration_seconds` is populated on a freshly
   uploaded video lesson after rollout.

   `pdfinfo` (poppler-utils, already requirement 3 above) is now also
   read for Academy PDF lessons, to measure `page_count` at upload time.
   That measurement is what makes the PDF gate un-forgeable; without it
   the gate falls back to the page count the browser reports, which a
   determined learner can under-report. New `PDFINFO_PATH` env override
   for the same TASK-093 shared-hosting reason as the others.

### 3. `is_downloadable` copy — do not overstate it

ADR-028 §2.2 and TASK-145 R3: the Admin UI must say **"ซ่อนปุ่มดาวน์โหลด"**,
never "ป้องกันการคัดลอก". Once a browser renders a PDF it holds the bytes.
The flag raises friction and records intent; it is not protection, and a
company may make real disclosure decisions about confidential material on
the strength of what we tell them here.

**Not yet run** — same sandbox limitation as every prior round (no PHP
binary available to ag-dev). New feature tests live at
`tests/Feature/Academy/ModuleLessonFileTest.php`,
`tests/Feature/Academy/LessonStreamRangeTest.php` and
`tests/Feature/Academy/LessonProgressCompletionTest.php`. Please run
`php artisan test --filter=Academy` plus `--filter=ProductMedia` and
`--filter=ProductSalesMaterial` (the range change touches both of those
stream endpoints) once you have migrated.

---

## Upload size limits — `post_max_size`, `upload_max_filesize`, `client_max_body_size`

**Read this before someone raises a php.ini limit to "fix" a failed
upload.** In most cases they do not need to, and on shared hosting they
cannot.

### What actually limits an upload

Three separate ceilings sit in front of every file, applied in this
order. The **smallest** one wins, and only the last of the three produces
a Laravel validation error an admin can read:

| Limit | Where | Applies to | What a breach looks like |
|---|---|---|---|
| `client_max_body_size` | nginx (`http`/`server`/`location`) | the whole request | **413** from nginx. Never reaches PHP, so no JSON body and no Laravel log line. Apache's equivalent is `LimitRequestBody`; MAMP's default is unlimited. |
| `post_max_size` | `php.ini` | the whole POST body | **413**, or an empty `$_POST` and `$_FILES` with PHP just dropping the payload. Also never reaches Laravel's validator. |
| `upload_max_filesize` | `php.ini` | each individual file | PHP marks the file as failed; Laravel reports *"The file failed to upload."* — misleading, since nothing about the file itself is wrong. |

`upload_max_filesize` must be ≤ `post_max_size`, and `post_max_size`
must be ≤ `client_max_body_size`, or the larger value is simply
unreachable. PHP's stock defaults are **`post_max_size = 8M`** and
**`upload_max_filesize = 2M`** — the 2M is the one that surprises people,
because a 2.5 MB image fails on a host whose `post_max_size` looks
generous.

### Why you usually do not have to raise them (TASK-094 chunked upload)

Every upload surface in the Admin app — product media, product spec
attachments, sales materials, announcement media, **and since TASK-145
Academy lesson files (video / PDF / image)** — sends anything larger than
**1 MB** as a sequence of small requests instead of one big one:

```
POST /api/v1/uploads/init            → { token, chunk_bytes }
POST /api/v1/uploads/{token}/chunk   → repeated, sequentially
POST /api/v1/modules/{m}/lessons     → with `upload_token` instead of `file`
```

Each chunk is `media.upload.chunk_mb` (default **5 MB**, `MEDIA_UPLOAD_CHUNK_MB`)
— comfortably under every default above — so **no environment has to raise
a PHP limit for a large file to succeed**. That was the explicit
constraint from the human on TASK-094 ("ถ้าไปปรับขนาดจะมีปัญหากับ
production"). The `resolve.chunked-upload` middleware reassembles the
parts into a normal uploaded file *before* validation, so the endpoint's
mime and size rules apply identically either way.

Two consequences worth knowing:

- **`chunk_mb` is the value that must fit inside the limits**, not the
  file size. If a host has an unusually small `post_max_size`, lower
  `MEDIA_UPLOAD_CHUNK_MB` rather than raising the host limit; the
  frontend reads the real per-chunk ceiling back from `/uploads/init`.
- **Cancelling mid-upload leaves a `.part` file behind.** The scheduled
  `uploads:prune` command deletes anything older than
  `media.upload.stale_hours` (default 24). Make sure the scheduler is
  actually running in production (`php artisan schedule:work` or a cron
  entry), or abandoned parts accumulate.

### If you do need to raise them anyway

For a direct (non-chunked) upload path, or a host you control:

```ini
; php.ini  — MAMP: /Applications/MAMP/bin/php/php8.3.x/conf/php.ini
post_max_size = 64M
upload_max_filesize = 64M
max_execution_time = 300      ; a slow mobile connection, not CPU
memory_limit = 256M
```

```nginx
# nginx — server{} or the /api location
client_max_body_size 64M;
```

Restart PHP-FPM / Apache after editing `php.ini`; a reload is not enough.

### Application-level ceilings (BR-7, not php.ini)

These are ours, and are the ones an admin should be tuning:

- **Academy lesson file (PDF / image): 20 MB, platform-wide** —
  `config('media.pdf.max_upload_mb')`, `MEDIA_PDF_MAX_UPLOAD_MB`.
  ADR-028 §4 (human decision) deliberately made this platform-wide with
  **no per-company override**; do not add one.
- **Video: per-company**, `video_processing_settings.max_upload_mb`
  (default 200), editable in the Admin UI.

A file that passes the transport limits but exceeds one of these gets a
proper Thai validation message, which is the outcome to aim for.
