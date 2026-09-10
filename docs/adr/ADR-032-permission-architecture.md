# ADR-032 — Permission architecture: one ability catalogue, one choke point

- **Status:** proposed (awaiting human approval of the phasing)
- **Date:** 2026-08-13
- **Human decisions feeding this:** back-office staff need different access (accountant sees only
  commission, trainer only Academy); Admin user management is incomplete; the security gaps are
  real. Wanted level: **per-company feature toggles AND per-company custom roles with an ability
  matrix**.
- **Supersedes nothing.** Amends the scope note in `App\Enums\UserRole` ("not a permissions
  matrix") — that note was correct for TASK-001 and is what this ADR changes.

---

## 1. Context — what the audit found

`UserRole` has three fixed cases. There is no permissions table, column, package or enum anywhere.
Authorization today = **role + `company_id` + record ownership**, and nothing else: not one of the
41 Policies reads `is_team_leader`, approval status or cert tier.

**The number that decides this ADR:**

| Where authorization is decided | Sites |
|---|---|
| Inside `app/Policies` | ~183 |
| **Outside any Policy** | **~253** |

The outside-Policy sites are: 17 `abort_unless(role)` in Controllers · 12 Form Requests whose
`authorize()` is a raw role check · ~35 `Rule::prohibitedIf(! isSuperAdmin())` — authorization
expressed as *validation* · ~30 Services re-deriving scope from role · ~25 Controllers narrowing
their own index query · 4 Resources deciding field-level disclosure.

There are **no `Gate::define` calls and no role middleware** in the codebase.

## 2. Decision

### 2.1 The load-bearing consequence of §1

**A permission system installed behind Laravel Gates would not cover the majority of the places
this system actually decides access.** The result would be a UI that hides buttons over an API
that still answers — worse than having no permission system, because everyone would believe there
was one.

**Therefore: consolidation comes first, and it is not optional.** Phase 1 below moves every
authorization decision behind one choke point **without changing a single access outcome**. Only
after that does a permission model mean anything.

This ordering is the whole point of this ADR. A plan that starts with the roles UI is a plan that
ships a decoration.

### 2.2 `Ability` — a closed set defined in code

A PHP enum, one case per thing a person can be permitted to do (`commission.view`,
`commission.mark_paid`, `academy.author`, `agent.create`, `agent.change_role`, …).

**Admins pick from this list; they never type their own.** Adding an ability is a code change plus
an ADR — exactly the shape ADR-026 chose for `PipelineStage`, and for the same reason: a
free-text permission name is a permission nobody can enforce.

### 2.3 The base role stays

`UserRole` remains the coarse tier. `TenantScope` keys on `isSuperAdmin()`, and 41 Policies key on
the three cases; replacing that is a rewrite this system does not need.

**A company role narrows within `company_admin`; it can never widen beyond it.** An agent cannot
be given `commission.mark_paid` by any grant. This keeps BR-6 and §5's three visibility levels
exactly as they are — the tenant boundary is never a matter of configuration.

### 2.4 One resolver, two inputs

```
may(user, ability) :=
      companyHasFeatureEnabled(user.company_id, ability)
  AND userRoleGrantsAbility(user, ability)
```

This is how the human's two requests (per-company toggles **and** per-user roles) become one
question rather than two systems that can disagree. A feature switched off for a company cannot be
re-enabled for an individual by any grant — the company-level answer is a ceiling, never a floor.

`commission_split_settings.is_enabled` and `team_visibility_settings.is_enabled` are the existing
precedent for the first input and should be migrated onto it rather than left as a parallel
mechanism.

### 2.5 Fail closed

An unreadable or missing setting means **not permitted**. Same rule
`CommissionSplitSettingService` already follows, and the same rule the audit says the current
`companies.is_active` broke by meaning nothing at all.

### 2.6 Every grant is audited

TASK-183 closed the six existing gaps (role change, team-leader grant, manager change, create,
deactivate, password reset). Every ability grant and revocation joins them. CLAUDE.md §6 already
requires this — *"every action that affects … permissions"*.

## 3. Phases

**Phase 0 — TASK-183. DONE.** A deactivated company now actually blocks access, and rights changes
are audited. Shipped ahead of this ADR at the human's instruction.

**Phase 1 — consolidate. No behaviour change.**
Define `Ability`. Route all ~253 outside-Policy sites and the 41 Policies through one resolver.
Every site keeps its current outcome; the tests prove it by asserting the *same* allow/deny matrix
before and after. This is the largest phase and the least visible — and the one that makes the
rest true. Includes replacing the 16 duplicated `role === 'super_admin'` computeds in the Admin app
with one helper fed by the server's answer, so the UI stops deciding for itself.

**Phase 2 — per-company feature toggles**, unified into §2.4's first input. Existing `*_settings`
kill switches migrate onto it. Small, visible, and useful on its own.

**Phase 3 — custom company roles + the ability matrix UI.** `company_roles` +
`company_role_abilities`, `users.company_role_id`. The Admin screen presents the `Ability` catalogue
grouped by area. This is the feature the human asked for; it is cheap once Phase 1 exists and
impossible to do honestly before it.

**Phase 4 — finish Admin user management.** Company invite-code CRUD (TASK-022, still pending — the
table and the public resolver exist but there is no way to create a code except editing the
database), `phone` missing from user creation, and the remaining inconsistencies the audit listed.

## 4. Consequences

**Accepted:**
- Phase 1 is a large diff with no demo. It will look like no progress for its duration. That is the
  price of §2.1 and it is not negotiable — attempting Phase 3 first produces a security theatre.
- Adding an ability is a code change. Deliberate (§2.2).
- Company Admin gains a genuinely powerful screen. Hence §2.6, and hence §2.3's ceiling.

**Open, to settle before Phase 3:**
- Whether a company role may be granted to an `agent`-tier user at all, or only to `company_admin`.
- What happens to a user whose company role is deleted. Proposal: fall back to their base role's
  default abilities, never to "all".
- Whether Super Admin may edit another company's roles, or only view them.

## 5. Alternatives considered

- **A permissions package (spatie/laravel-permission).** Rejected for now: it solves the storage
  and the Gate wiring, neither of which is the hard part here. It would not touch the ~253 sites,
  and adopting it before Phase 1 would hide that fact behind a dependency.
- **Department × tier matrix** (as in the human's other project). Rejected: this platform's
  structure is agents-under-a-company, not departments; a matrix would model an organisation this
  product does not have.
- **Per-user ad-hoc grants with no roles.** Rejected: unauditable in practice — nobody can answer
  "who can mark commission paid?" without scanning every user.

---

## 6. Amendment — 2026-09-10: per-user grants, shipped ahead of Phase 3

**Human request:** *"เพิ่มเรื่องการกระจายสิทธิ์ให้ Company Admin และ Admin ที่ได้สิทธิ์ในการตัดได้เฉพาะหน้าการตัดสิทธิ์ เพราะทำงานคนละหน้าที่กัน"* — asked while
reviewing voucher redemption, and answered **"ทำทั้งสองอย่าง"** when offered a front-desk role or
per-person grants.

### 6.1 What shipped

- `user_abilities` (`user_id`, `ability`, `granted_by_user_id`, unique per pair). A row means
  **also may**; there is no row shape for "may not", so the role table in `PermissionResolver`
  stays the readable answer to what a role holds and a grant can never quietly subtract from it.
- `UserRole::VoucherStaff` — a front-desk tier that reaches the redemption endpoints and nothing
  else, enforced by `RestrictVoucherStaff`, an **allowlist at the door** rather than edits to
  every Policy. Most admin gates ask `isCompanyAdmin()` and refuse a new role for free, but
  `OrderPolicy::viewAny()` returns true for any authenticated user — whether the next one of those
  leaks would depend on somebody remembering this role exists while writing it.
- `PUT /users/{user}/abilities`, replacing the whole set for one person, audited as
  `user.abilities_updated` when — and only when — the set actually changed.
- `Ability::VoucherRedeem` **removed from the `company_admin` row.** It is now held only by the
  front-desk role or by an explicit grant. A migration backfills a grant for every existing
  `company_admin` so nobody loses an ability on deploy; from that point on it is given, by
  somebody, on purpose.

### 6.2 Why this is not the alternative §5 rejected

§5 rejected *"per-user ad-hoc grants with no roles"* as *"unauditable in practice — nobody can
answer 'who can mark commission paid?' without scanning every user"*. That objection is answered
rather than ignored:

- **The set is closed.** `UserAbilityController::GRANTABLE` is a constant, today holding one
  entry. Widening it is a code change plus review — the same shape §2.2 chose for the catalogue
  itself. Without it a Company Admin could grant themselves `settings.payment_gateway.update`,
  the one ability ADR-027 withholds from them precisely because it names the bank account their
  company's revenue lands in.
- **The question is answerable on screen.** `GET /users?with_abilities=1` eager-loads the grants
  (one query for the page, not one per row), and จัดการผู้ใช้ระบบ badges every holder and counts
  them in its header line. "Who may redeem?" is one glance, not a scan.
- **Roles did not go away.** The role remains the answer for somebody whose whole job is the
  counter; the grant exists for the person who does two jobs. §2.3's ceiling is untouched — a
  grant narrows within a tier and never widens past it, and `voucher.redeem` is not grantable to
  an `agent` in any case, since this app refuses that role at the door.

### 6.3 What Phase 3 still owes

This is not `company_roles`. It is one table, one endpoint and one grantable ability, built
because a real permission needed distributing before the role editor exists. Phase 3 subsumes it:
when custom roles ship, a company role holding `voucher.redeem` replaces the per-person grants,
and `user_abilities` stays as the exception mechanism it is here. The three questions §4 lists as
open before Phase 3 are unaffected — none of them is decided by this amendment.
