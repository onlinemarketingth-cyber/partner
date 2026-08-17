# TASK-183 — a deactivated company must actually stop working

- **Owner:** ag-lead (spec) → ag-dev → ag-qa
- **Date:** 2026-08-13
- **Priority:** urgent. Human decision: *"แก้ทันที แยกเป็นงานด่วนก่อนแผนหลัก"* — this ships before
  the permission-system plan starts, not as part of it.
- **Related:** CLAUDE.md §5 (multi-tenancy), §6 (audit log), BR-6

---

## 1. The two defects

### 1.1 `companies.is_active` and a soft-deleted company are enforced NOWHERE

`backend/app/Services/Platform/CompanyService.php:13-19` says so in its own words:

> *"deactivating (`is_active = false`) or soft-deleting a company does NOT currently block its
> users from logging in or acting — TenantScope/Sanctum auth never checks
> `company.is_active`/`deleted_at`."*

A grep confirms it: outside the model's `$fillable`/casts, the Resource and the two Form
Requests, **nothing reads the column**. An admin who closes a company sees the switch flip and
reasonably concludes access has been withdrawn. It has not: every user of that company continues
to log in, sell, submit referrals, and have commission written for them.

**A control that visibly does nothing is worse than no control**, because someone relies on it.

### 1.2 Nothing is recorded when a user's rights change

CLAUDE.md §6 requires an audit row for *"every action that affects money, commission, status,
certification, **or permissions**"*. No `audit_logs` row is written when:

| Action | Writer with no audit |
|---|---|
| **`role` changes** (agent ↔ company_admin) | `UserService::update()` |
| **`is_team_leader` granted or revoked** | `UserService::update()` — the most permission-like write in the system |
| **`manager_id` changes** | `UserService::assignManager()` (`:139-143` says so explicitly) — and this silently changes who may approve whom (`LeaderRecruitScope`) |
| **user created** | `UserService::create()` |
| **user deactivated / restored** | `UserService::deactivate()` / `restore()` |
| **password reset by an Admin** | `UserService::resetPassword()` |

Bank-account and national-ID changes *are* audited, so the writer, the masking helpers and the
shape all already exist — this is extending a working pattern, not inventing one.

## 2. Scope

**In:** enforcing company status; the six audit gaps above.

**Out:** the permission system itself (ADR-032 and its phases), invite-code management, any change
to who may do what. **This task changes no authorization rule** — it makes an existing one real
and makes existing changes visible.

## 3. Requirements — company status (§1.1)

**3.1 One predicate.** "May this company operate?" is answered in exactly one place, e.g.
`Company::isOperational()` or a small service. `is_active === true` **and** `deleted_at === null`.
Do not spread the two conditions across call sites — this codebase has spent the week removing
duplicated predicates.

**3.2 Block at login.** `LoginGateService` already orders its refusals deliberately
(`:86-125`: Rejected → Unverified → Pending). Add company status and **state where in that order
it belongs and why** in the docblock, as the existing entries do. A Super Admin has
`company_id === null` and must never be locked out by this.

**3.3 Block on every authenticated request, not just login.** A session or Sanctum token minted
before deactivation must stop working. Login-only enforcement means "deactivate" takes effect at
the next login, which for an active user could be never. Middleware on the authenticated route
group is the natural home; if you choose otherwise, justify it.

**3.4 Fail closed, and say which error.** Decide and document the status code and the Thai
message. It must be distinguishable from a password failure — the user needs to know to contact
their company, not to reset their password. Do not leak whether the company merely exists.

**3.5 Public endpoints too.** Registration via invite code, registration via a recruit link, the
public payment page, product-share landing and affiliate lead capture all act on behalf of a
company **without an authenticated user**, so `TenantScope` does not apply (it returns early with
no filter when there is no user — `TenantScope.php:67-69`). Audit each public endpoint and refuse
where the company is not operational. **List them in your report even if you conclude one needs
no change.**

**3.6 Existing data.** Confirm what `is_active` is for existing rows and that no company is
accidentally left non-operational by this change. Report the check you ran.

## 4. Requirements — audit (§1.2)

**4.1** Write an `audit_logs` row for each of the six actions in §1.2, following the shape already
used by `user.bank_account_updated` (`UserService.php:74-83`): `action`, `auditable_type/id`,
`old_values`, `new_values`, `actor_user_id`, `company_id`, `ip_address`.

**4.2 Never log a secret.** The password-reset row records **that** a reset happened and by whom —
never the password, not even hashed. Same rule as the bank-account masking.

**4.3 Name the actions consistently** with the existing vocabulary (`user.bank_account_updated`,
`agent_approval.approved`, …). Suggested: `user.created`, `user.role_changed`,
`user.team_leader_changed`, `user.manager_changed`, `user.deactivated`, `user.restored`,
`user.password_reset_by_admin`. If you deviate, say why.

**4.4 Self-service too.** `UserProfileService::updatePassword()` is equally unaudited. Decide
whether a user changing their own password warrants a row and **state your reasoning** — do not
silently skip it.

## 5. Tests

- A user of a deactivated company cannot log in; the message names the company status, not a
  credential failure
- A user **already holding a valid session/token** when the company is deactivated is refused on
  the next request — this is §3.3 and is the assertion most likely to be missing
- A soft-deleted company behaves identically to `is_active = false`
- A Super Admin (`company_id === null`) is unaffected
- Each public endpoint in §3.5 refuses for a non-operational company
- One test per audit action asserting the row exists with the right `action`, actor and
  old/new values — and, for the password reset, asserting **no password material appears anywhere
  in the row**
- **Mutation-check the lot**: remove each guard, observe the failure, restore, report the counts

## 6. Definition of Done

CLAUDE.md §9, plus: one predicate for company status, enforcement on every authenticated request
and on the public endpoints, and an audit row for all six rights-affecting writes.
