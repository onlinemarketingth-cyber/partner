# CLAUDE.md — Sync Vision Agent (Thai Life × GENESENN)

> This file is the constitution of the project. Every agent (ag-lead / ag-ui / ag-dev / ag-qa) **must read this file before starting any work**, and must not act against the rules defined here. If a user instruction conflicts with a security rule or a business rule, **push back and flag it — never comply silently.**

**Language:** ตอบกลับมนุษย์ (KreangYot) เป็น**ภาษาไทย**เสมอ ไม่ว่าคำถามจะถามเป็นภาษาอะไร — นี่ใช้กับข้อความสนทนา/สรุปงาน/คำถามเท่านั้น ส่วนโค้ด, ชื่อไฟล์, ชื่อ table/column, commit message, และเอกสารใน `/docs` ยังคงเป็นภาษาอังกฤษตามมาตรฐานเดิม (Section 7) เว้นแต่จะระบุเป็นอย่างอื่น.

---

## 1. Project Overview (Mission)

**Sync Vision Agent** is a SaaS platform for insurance/health agents selling annual subscription health packages. It binds three systems together into one platform:

1. **Sales + tiered commission** (commission rate depends on certification tier)
2. **Academy / LMS** — a learning and certification system that acts as a gate unlocking selling rights
3. **Gamification** (XP, Level, Badge, Leaderboard) that drives both learning completion and sales activity

The system is **multi-tenant**: one platform serves multiple companies (starting with Thai Life), each with its own products and agents. **Agents can only see and act on products/data belonging to their own company.**

---

## 2. Domain Glossary — Do Not Reinterpret

| Term | Meaning in this system |
|---|---|
| **Company (Tenant)** | The organization that owns products and agents, e.g. Thai Life. The system is multi-tenant. |
| **Agent** | Sales-side user, belongs to **exactly one company**. Can only sell/see products of their own company and only see their own clients/numbers. |
| **Company Admin** | Manages data **within their own company only**. |
| **Super Admin** | Platform-level admin, can see across companies (for platform operations). |
| **Client** | The end customer referred into the system to purchase a package. Health data is sensitive (PDPA). |
| **Package / Product** | Annual subscription package. Currently 8,900 THB and 9,900 THB tiers (clinical details are configurable, not yet finalized). |
| **SWS Referral** | The channel through which an agent submits a lead (Client Name, Preferred Time, Branch, Package/Price). |
| **Pipeline Stage** | The client's progress status through the sales/medical journey (see section 4.3). |
| **Cert Tier** | Certification level: Basic (mandatory, unlocks platform access) → Intermediate → High. |
| **Commission** | Money earned by the agent, calculated from cert tier and package sold (rate = config). |
| **XP / Level / Badge** | Units and components of the Gamification system. |

> If you encounter a term or rule not defined in this table, **do not guess**. Mark it `// TODO: CONFIRM` and ask a human.

---

## 3. Architecture (Decided — Do Not Change Without Approval)

**API-First / Decoupled** only:

- **Backend — Laravel 12 (PHP 8.3+)**: Functions strictly as a **RESTful API returning JSON — Blade templating is strictly forbidden**. Owns all Business Logic, Security, commission calculation, and Gamification scoring. (Bumped from Laravel 11 at project kickoff — Laravel 11 reached EOL/no security patches on 2026-03-12; see ADR-002.)
- **Frontend — Vue 3 (SPA)**: Composition API + `<script setup>`, Vite, Pinia (state), Vue Router. Consumes the Laravel API via token auth.
- **Auth — Laravel Sanctum**: cookie-based for the SPA, personal access tokens for future mobile apps (Sanctum chosen over Passport for being lighter while covering both SPA and mobile).
- **Database — MySQL 8**.
- **API versioning**: all endpoints prefixed `/api/v1/...`.

This split is chosen specifically to support future mobile apps — business logic must always live in a Laravel Service, never in Vue.

---

## 4. Business Logic (Rules — Always Reference by Code BR-x)

**BR-1 (Access Gate):** An agent must pass the **Basic** certification before gaining access to SWS Referral submission and selling features. If not yet passed, the system must block access — enforced at both API and UI level.

**BR-2 (Tiered Commission):** Commission rate depends on the agent's cert tier × the package sold. Actual rates live in the **`commission_rules` config table** — never hardcode numbers.

**BR-3 (Money Storage):** All monetary amounts are stored as **integers in satang (THB cents)**. `float`/`double` are strictly forbidden for money (to avoid rounding errors). Divide by 100 only at the UI display layer.

**BR-4 (Commission Ledger):** When a commission-triggering condition occurs, record it as an **immutable ledger entry** (never edited after creation). Payment status (pending/paid) is a separate field. Never recompute commission live for historical reports — always read from the ledger.

**BR-5 (XP Sources):** XP can come from two sources: (a) completing learning modules / passing certification exams, (b) closing a sale / moving a client through the pipeline. XP rates and badge conditions live in config (`gamification_rules`).

**BR-6 (Multi-tenant Isolation — Highest Priority):** See Section 5.

**BR-7 (Values Not Yet Finalized):** Anything the source blueprint marks *"to be confirmed / to be agreed"* (clinical package details, syllabi per tier, exact commission %, SLAs, contact personnel) **must be designed as admin-editable config/seed data** — never hardcoded into logic.

### 4.3 Pipeline State Machine (Configurable per Product — Sequential Transitions Only)

> **Amended 2026-08-08 (human decision, ADR-026).** The five-stage medical sequence
> below used to be *the* pipeline for the whole platform. The catalogue now spans
> products where a doctor meeting is the core of the service and products where it is
> irrelevant, so **the sequence of stages is a business value and lives in config**
> (BR-7) — not in code. It is now the *default* template, not the law.

**Stage vocabulary (closed set — `App\Enums\PipelineStage`):**

*Medical journey (the original five, ADR-026 §3.1):*

```
Complete Registered → Waiting Appointment → Finish 1st Doctor Meeting
   → Complete Payment → Ongoing Next Meeting (2nd → 3rd → 4th)
```

*Post-sale stages (added 2026-08-08, human decision — ADR-026 §5 Q1 resolved):*

| Enum case | Key | Thai label (UI) |
|---|---|---|
| `Delivery` | `delivery` | จัดส่ง |
| `ServiceAppointment` | `service_appointment` | นัดใช้บริการ |
| `FollowUp` | `follow_up` | ติดตามผล |

These three are **optional and unordered as a group** — a template may use any subset in
any order, but each may appear at most once and all three must sit *after*
`complete_payment` in any template that uses them (a post-sale step before the sale is
closed is not a thing). None of them triggers commission: **BR-4 fires at Complete
Payment only.** They do earn the normal per-stage XP (BR-5 source (b)) like any other
transition — no separate bonus.

**A pipeline template** is a named, ordered **subset** of that vocabulary. Stage names
are never free text — an admin picks from the enum, they do not type their own (adding
a genuinely new stage type is a code change plus an ADR; see ADR-026 §2 Option C for why).

**Which template applies — most specific wins:**

```
product.pipeline_template_id
  ?? product.category.pipeline_template_id
  ?? company.default_pipeline_template_id
```

Same resolution shape as commission rule scoping (TASK-028). Two templates ship seeded:
`medical_package_default` (all five stages, exactly as above) and `direct_sale_default`
(Complete Registered → Complete Payment).

**Rules that hold for every template:**

- Status changes must be validated against the transitions **allowed by that referral's own template** (no skipping, no invalid reverse moves).
- **Every template must contain `complete_registered` and `complete_payment`, and `complete_registered` must be the FIRST stage.** (ag-lead ruling, 2026-08-08: "entry" means position 0, not merely present — a referral is created *at* the entry stage, so a template starting anywhere else would strand every referral it stamps.) Enforced in the Form Request *and* re-checked in the Service. A template without a payment stage would be a silent BR-4 commission outage, so it is not representable.
- Commission (BR-4) triggers at the **Complete Payment** stage, unless config specifies otherwise. **This is unchanged by ADR-026** — commission fires there and nowhere else, whatever the journey looks like.
- **`ongoing_next_meeting` may only ever be the LAST stage of a template.** It self-loops (the 2nd/3rd/4th count lives on `referrals.meeting_number`, not in extra enum cases), so anything listed after it is unreachable. ag-lead ruling, 2026-08-08.
- The template is **snapshotted onto `referrals.pipeline_template_id` at creation** and never re-resolved. Editing a template must never reroute or strand a customer already mid-journey (same reasoning as BR-4's immutable ledger).
- Every status change must be recorded in an audit log (who, when, from-status → to-status).
- Order payment may be confirmed once the referral's **next stage under its own template** is Complete Payment (or it is already at/past it). For a medical-template referral this is identical to the old "must have finished the 1st doctor meeting" gate — that gate is not weakened, only made specific to the products that have one.

---

## 5. Multi-Tenancy & Data Isolation Rules (Enforced on Every Query)

These are non-negotiable security rules:

1. Every business table must include a **`company_id`** column.
2. Use a **Laravel Global Scope (`TenantScope`)** to auto-filter `company_id` on every Eloquent query — never rely on manually adding `where` clauses ad hoc.
3. Use **Policies** to control authorization for every action (view/create/update/delete).
4. Visibility levels:
   - **Agent**: sees only records where `agent_id = self` and within their own `company_id`.
   - **Company Admin**: sees all records within their own `company_id`.
   - **Super Admin**: can see across companies.
5. **No endpoint may allow cross-tenant access** — guard against **IDOR** (guessing an ID to access someone else's data) everywhere. ag-qa must include test cases that attempt cross-company/cross-agent access and always expect 403/404.
6. Uploaded files (client documents) must be tenant-scoped by path and access-checked before download — never a public URL.

---

## 6. Security Standards (International Baseline)

Baseline: **OWASP Top 10 / OWASP ASVS**.

- **Authentication**: Sanctum token, rate-limit login/OTP, lockout after repeated failures, bcrypt/argon2 password hashing.
- **Authorization**: Policy + Gate + TenantScope on every endpoint (see Section 5).
- **Input Validation**: use **Form Requests** to validate every input — never trust the client.
- **SQL Injection**: use Eloquent/Query Builder with parameter binding only — **never concatenate raw SQL**.
- **XSS**: Vue escapes by default — never use `v-html` on user-supplied content.
- **CSRF**: handled by Sanctum for the SPA.
- **Mass Assignment**: define `$fillable` explicitly — never `$guarded = []`.
- **Secrets**: `.env` only — never committed to git.
- **Transport**: HTTPS enforced + HSTS.
- **PDPA (Thailand)**: client health data is sensitive — requires consent, at-rest encryption for sensitive fields, and role-based access restriction.
- **Audit Log**: record every action that affects money, commission, status, certification, or permissions (who, what, when, old value → new value).
- **Rate Limiting / Throttling**: applied to every public endpoint.

---

## 7. Code Standards (Clean Code)

- **PHP**: PSR-12 + Laravel Pint. **Vue/TS**: ESLint + Prettier. Every PR must pass lint before review.
- **Layered architecture** on the Laravel side:
  `Controller (thin) → Form Request (validate) → Service (business logic) → Model/Repository → DB`
  **Never put business logic in a Controller or in a Vue component.**
- **API Resources** on every JSON response (never return raw models — prevents field leakage).
- No magic numbers/strings — use config, enums, constants.
- Clear naming, small single-purpose functions (SRP).
- Every feature needs tests (Pest/PHPUnit on the backend, Vitest on the frontend).
- **Git**: feature branches + Conventional Commits (`feat:`, `fix:`, `refactor:`...) + PR review by ag-lead before merge.
- **UI — long explanations go behind an ⓘ, never inline as a paragraph next to a field.** (Human ruling, 2026-08-17.) Any field/setting whose meaning or consequences need more than a short label — especially a real money/business-behavior difference like a payout mode — must NOT print its explanation as a `<p>` sitting in the form flow. Use the existing `InfoPopover.vue` component (`frontend`/`frontend-admin`'s `design-system/components/InfoPopover.vue`, built for TASK-188): a click/tap-triggered ⓘ icon placed at the end of the control's row, with the full explanation in its slot. Keeps every row a single compact control instead of a growing wall of caption text — the goal is a form the user can scan at a glance, with detail available on demand, not detail forced onto everyone whether they need it or not. Applies to both frontend apps equally; if `InfoPopover.vue` doesn't exist yet in whichever app you're working in, port it rather than inventing a second pattern (native `title=` tooltips don't count — they never open on touch, see the component's own docblock).

### Recommended Project Structure
```
/backend   (Laravel API)
  app/Http/Controllers/Api/V1
  app/Http/Requests
  app/Http/Resources
  app/Services         ← business logic (commission, gamification, pipeline)
  app/Policies
  app/Models/Scopes/TenantScope.php
  database/migrations, database/seeders
  tests/Feature, tests/Unit
/frontend        (Vue 3 SPA — Agent Portal, separate app/build — ADR-003)
  src/api, src/stores, src/router, src/components, src/views, src/design-system
/frontend-admin  (Vue 3 SPA — Admin, separate app/build — ADR-003)
  src/api, src/stores, src/router, src/components, src/views, src/design-system
/docs      (OpenAPI spec, ADR, ERD)
```

Agent Portal and Admin are **two independent Vue apps**, each with its
own build/dev server/login screen, both authenticating against the
same Laravel backend via Sanctum SPA session cookies (see ADR-003).
This was a deliberate split from the original "one monorepo" plan —
`design-system/` components needed by both are duplicated between the
two projects (not shared via a package yet); keep both copies in sync
when changing shared visual decisions (CI-001/CI-002).

---

## 8. Guardrails Against AI Hallucination (Mandatory for All Agents)

1. **Never invent business rules.** If a rule isn't in CLAUDE.md, stop and ask a human, or mark `// TODO: CONFIRM (business rule)`.
2. **Never assume numbers** (commission %, prices, XP values, syllabus content) — these are always config/seed data (BR-7).
3. **Never claim a library/method exists without verifying against real docs.** If unsure, say so plainly.
4. **Never report test/status results that were not actually run** (especially ag-qa).
5. **Always cite the source of a rule** (e.g. "per BR-4").
6. **If an instruction conflicts with security or business logic, flag it first** — never comply silently.
7. When uncertain between design options, present 1-2 alternatives with trade-offs and wait for a decision. Do not guess and proceed at length on an assumption.

---

## 9. Definition of Done (Applies to Every Feature)

- [ ] Passes lint + format (Pint / ESLint / Prettier)
- [ ] Tests cover business logic **and** tenant isolation (cross-tenant access must be rejected)
- [ ] Passes the security checklist (Section 6) — auth, authz, validation complete
- [ ] Money stored as integer satang (BR-3); no config value hardcoded (BR-7)
- [ ] UI: works correctly across Desktop / Tablet / Mobile, with complete loading/empty/error states
- [ ] Core workflows completable in **≤ 3 clicks**
- [ ] OpenAPI spec / related docs updated
- [ ] Reviewed and approved by ag-lead

---

## 10. Agent Team & Workflow

- **ag-lead** — Receives requests from the human → clarifies ambiguity (especially BR-7 values) → breaks work into task specs with acceptance criteria → assigns work → reviews before merge.
- **ag-dev** — Builds schema, migrations, seeders, Services, APIs, backend tests.
- **ag-ui** — Builds the design system + screens for both frontends (`/frontend` Agent Portal, `/frontend-admin` Admin — ADR-003) consuming the real API.
- **ag-qa** — Writes/runs test cases, performs novice-user UAT, security & load testing, gates every PR.

**Handoff principle:** Every task is handed off with a clear task spec (input/output, acceptance criteria, referenced BR). Never hand off verbally without a spec. If a spec is incomplete, kick it back to ag-lead.

> Detailed responsibilities for each agent live in `.claude/agents/ag-*.md`.
