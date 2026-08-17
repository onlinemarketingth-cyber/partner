# TASK-185 — Phase 1 foundation: the ability catalogue, the resolver, and the safety net

- **Owner:** ag-lead (spec) → ag-dev
- **Date:** 2026-08-13
- **Related:** ADR-032 (read it first), CLAUDE.md §5, §6, BR-6
- **Blocks:** TASK-186 … TASK-190 (every conversion task in Phase 1)

---

## 1. What this task is, and what it is not

ADR-032 §2.1: authorization in this system is decided in ~253 places **outside** any Policy and
~183 inside. Phase 1 moves all of them behind one choke point **without changing a single access
outcome for a single user**.

**This task builds the foundation and the safety net. It converts nothing.** No `abort_unless` is
removed, no Form Request is touched, no Policy changes. At the end of this task the application
behaves *exactly* as it does today, and there exists a test suite that would notice if that
stopped being true.

That sequencing is the point. A conversion without a characterization suite is a rewrite of the
security model by hand, in one commit, with no way to tell what moved.

## 2. Deliverable 1 — `App\Enums\Ability`

A PHP enum, one case per thing a person can be permitted to do. Naming: `area.action` —
`commission.view`, `commission.mark_paid`, `academy.author`, `agent.create`,
`agent.change_role`, `report.platform.view`, `settings.company.update`, and so on.

**Derive the catalogue from what the code does today, not from what a permission system ought to
have.** Read the audit sites (§4) and name the distinct *questions* they ask. If two sites ask
the identical question, they get one ability — that consolidation is the deliverable. If two
sites look similar but differ in one condition, they are **two** abilities until a human says
otherwise; do not merge on a hunch and quietly widen access.

Closed set, code-defined (ADR-032 §2.2). Adding a case is a code change plus an ADR.

Include a docblock per case saying, in one line, **which existing call site(s) it was derived
from** — file:line. That provenance is what makes the later conversions reviewable.

## 3. Deliverable 2 — `App\Services\Authorization\PermissionResolver`

One class. One public question: may this user do this ability?

**In Phase 1 the answer is a pure function of the base role**, because that is all the current
code consults. ADR-032 §2.4's other input (per-company feature toggles) arrives in Phase 2; leave
a clearly-named seam for it, but **do not implement it here and do not pretend to** — a resolver
that reads a table that does not exist yet is a resolver nobody can test.

- **Super Admin is not a blanket `true`.** Write out which abilities Super Admin holds. The audit
  found real cases where Super Admin is *excluded* (`UserPolicy::view` refuses a Super Admin
  target; `ExamAttemptPolicy::create` and `RewardRedemptionPolicy::create` are agent-only). A
  `Gate::before` returning true for Super Admin would silently grant all three. **Do not add a
  `Gate::before` blanket.**
- **Fail closed** (ADR-032 §2.5): an ability with no rule is denied, and that must be a test, not
  a convention.
- The role→ability map is the artifact this task exists to produce. Make it a readable table in
  one file, not logic scattered across methods.

Wire it to Laravel's Gate so call sites can eventually use `$user->can(Ability::X)`. Wiring only —
no call site uses it yet.

## 4. Deliverable 3 — the characterization suite (the important one)

**This is the safety net for the whole of Phase 1 and the reason this task exists.**

An HTTP-level test suite that records, for each of the three roles, the **actual current
allow/deny outcome** of every endpoint the later tasks will convert. Assert real status codes
against real routes — not the resolver, not a Policy method. The suite must be written against
today's untouched code and must pass today.

Cover, at minimum, the sites the next task (TASK-186) will convert:

- the **17 `abort_unless(role)` sites** in Controllers listed in the audit (PlatformReport,
  ComplianceReport, ConfigHealthReport, SalesTeamOverview, AgentDashboardMetrics,
  AgentCommissionSummary ×2, AgentTarget ×2, TeamVisibilitySetting, AcademyCompletionSetting,
  CommissionBinarySetting, CommissionMatrixSetting, CommissionGenerationSetting,
  AgentRankSetting, VideoProcessingSetting, CompanyTheme)
- the **12 Form Requests whose `authorize()` is a raw role check** — including
  `StoreUserCertificationRequest`, which is the **entire** authorization for a BR-1-unlocking
  write with no Policy behind it at all

For each: agent → ?, company_admin → ?, super_admin → ?, and **cross-tenant** (a company_admin of
another company → ?). Record what actually happens, including where it is a 403 vs a 404 vs a 422
— the distinction matters and the conversions must preserve it.

**Where the current behaviour looks wrong, do not fix it.** Record it, mark it
`// TODO: CONFIRM (behaviour recorded, not endorsed)` with a one-line note, and list it in your
report for ag-lead. Phase 1 changes nothing; a defect found here becomes its own task with its own
human decision. Silently "correcting" one during a no-behaviour-change refactor is the single
worst thing that could happen in this phase.

## 5. Constraints

- **No behaviour change.** If any existing test changes its expected outcome, stop and report.
- **BR-6** unaffected: `TenantScope` and the three visibility levels are untouched by this task.
- **CLAUDE.md §7** layering — the resolver is a Service.
- Do not install a permissions package (ADR-032 §5).

## 6. Verification

- The characterization suite passes against **unmodified** application code
- A test proving an unmapped ability is denied (fail-closed)
- A test proving Super Admin does **not** hold the three abilities the audit found it excluded from
- Existing suites still green
- `pint` clean on new files

Run the real command and report its real output. `php artisan test` may not run in your
environment; previous agents used `@php-wasm/cli@3.1.49` + PHPUnit directly against a **copy** of
`backend/` in `/tmp`, patching `RefreshDatabase::migrateDatabases()` to `Artisan::call(...)`
**in the copy only**. Delete the copy; never patch the real repo. **Never report a result you did
not observe** (CLAUDE.md §8 rule 4).

## 7. Definition of Done

CLAUDE.md §9, plus: the catalogue carries provenance for every case, the role→ability map is one
readable table, Super Admin is enumerated rather than assumed, and the characterization suite
passes on untouched code — so that TASK-186 onwards has something to break.
