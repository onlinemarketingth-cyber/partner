Task: Multi-tenant foundation (companies + users + TenantScope)
Owner: ag-dev (executed directly by ag-lead here as prototyping-to-unblock — see CLAUDE.md agent scope; no separate ag-dev session running in this project yet)
Goal: Lay the schema/model foundation every other feature (BR-1..BR-5) depends on — a Company (tenant), Users scoped to a company with a role, and the global tenant-isolation scope actually wired up and enforced.
Related: BR-6 (highest priority), Section 5 (Multi-Tenancy & Data Isolation Rules), Section 2 (Domain Glossary — Company/Agent/Company Admin/Super Admin), Section 1 (starting tenant: Thai Life)

Input:
- Existing `companies`-less `users` table (Laravel default)
- Existing `TenantScope` stub at `app/Models/Scopes/TenantScope.php` (has TODO: CONFIRM markers for role checks)

Expected output:
- `companies` table + `Company` model
- `users` table gains `company_id` (nullable FK) + `role` (backed by `App\Enums\UserRole`: Agent, CompanyAdmin, SuperAdmin)
- `TenantScope` wired for real (no more TODO stub) and applied to `User`
- `CompanyPolicy` (Super Admin only: create/update/delete companies; Company Admin/Agent: view own company only)
- Seeder: one company (Thai Life) + one user per role, dev-only credentials

Acceptance Criteria:
  - `company_id` nullable on `users` (Super Admin may have none) — NOT NULL for Agent/Company Admin enforced at the application layer (Form Request / Policy), not a hard DB constraint, since Super Admin is the exception
  - `role` stored as a string-backed PHP enum (`App\Enums\UserRole`), never a magic string (Section 7)
  - `TenantScope::apply()` filters by `company_id` for Agent/Company Admin, bypasses for Super Admin — verified by reading the implementation (no test runner available in this sandbox; ag-qa must add the actual cross-tenant Feature tests before this ships)
  - No commission/XP/package/pricing values touched or invented here (BR-7) — this task is schema/identity only
  - Migration is reversible (`down()` implemented)
  - Passes Pint formatting conventions (PSR-12) by inspection; ag-dev/ag-qa to run `./vendor/bin/pint` once Composer deps are installed locally

Out of scope (future tasks):
  - `agent_certifications` / cert tier progress (BR-1 access gate)
  - `products`/`packages`, `commission_rules`, `commission_ledger` (BR-2/BR-3/BR-4)
  - `gamification_rules`, XP/Level/Badge tables (BR-5)
  - SWS Referral / Client / Pipeline stage tables + audit log (Section 4.3)
  - Full RBAC (permissions matrix / `role_user` pivot) — a single `role` enum column is used instead, since Section 5 defines exactly 3 fixed visibility levels, not an arbitrary permission matrix. Revisit if that assumption changes.
  - `UserPolicy` beyond what `CompanyPolicy` needs (separate task)

Design notes (not in CLAUDE.md, decided here — flag if wrong):
  - Added `deleted_at` (soft delete) to `companies` and `users`. Not explicitly mandated for this project, but consistent with Section 6's audit-everything posture and BR-4's "immutable ledger" spirit — hard-deleting a tenant or user would orphan audit/commission history. Cheap to add now, expensive to retrofit later.
