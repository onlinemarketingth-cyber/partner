Task: Move user between companies (Super Admin only)
Owner: ag-dev + ag-ui — executed directly by ag-lead, no separate session running yet
Goal: Human-confirmed decision — Super Admin needs the ability to reassign a user (agent/company_admin) to a different company, without rewriting their historical earnings/activity trail.
Related: BR-4 (commission ledger immutability), BR-5 (xp ledger, same immutability shape), BR-6/Section 5 (multi-tenant isolation — this action changes which tenant a user belongs to going forward), Section 6 (audit log — "record every action that affects ... permissions")

Input: `User.company_id` (existing column, TenantScope'd), `AuditLog` (generic polymorphic audit table, existed from the original schema pass but had never been written to by any Service yet — this is its first real writer), `commission_ledger`/`xp_ledger` (both already store their OWN independent `company_id` captured at write time, confirmed by reading their migrations before writing this Service — this is what makes "don't rewrite history" possible without extra work).

Expected output:
- `UserPolicy::move()` — Super Admin only, and never on another Super Admin (a Super Admin has no company_id to move between in the first place).
- `MoveUserCompanyRequest` — `company_id` required, must reference a real company.
- `UserService::moveToCompany()` — wrapped in `DB::transaction()`: writes an `AuditLog` row (action `move_to_company`, old/new company_id, actor, IP) and updates `$target->company_id` atomically.
- `UserController::moveToCompany()` + route `POST /users/{user}/move-company`.
- Frontend (`AgentManagementView.vue`): "ย้ายบริษัท" button (Super Admin only, next to the existing role/deactivate/restore actions), inline company picker + confirm, with an explicit note that historical commission/XP stays with the old company.

Acceptance Criteria:
  - Super Admin can move an agent/company_admin to a different company; the user's `company_id` updates and all FUTURE actions (queries via TenantScope, new referrals/commission/XP) are scoped to the new company
  - Company Admin gets 403 attempting this action (even for their own company's agents)
  - Super Admin cannot move another Super Admin (403)
  - An `AuditLog` row is created with the correct actor, old company_id, and new company_id
  - Moving a user does NOT rewrite any existing `commission_ledger`/`xp_ledger` row's `company_id` — verified by asserting those rows keep their original company_id after the move
  - `company_id` must reference a real, existing company (422 otherwise)
  - `eslint`/`vue-tsc --build`/`vite build` clean for `frontend-admin`

Out of scope (future tasks):
  - Bulk/mass user moves
  - Any UI warning or confirmation beyond the one inline note about historical data — no "are you sure?" modal was requested
  - Retroactively reassigning a user's referred Clients or open Referrals to the new company (they stay associated with whichever company they were created under; TenantScope on those tables will naturally hide them from the user's new company's admins, which is the existing, correct multi-tenant behavior — not something this task changes)

Design notes (flag if wrong): chose to key the AuditLog row's own `company_id` field to the OLD company (where the action originated), not the new one — an arbitrary but defensible choice; flag if the human wants it the other way or wants both companies' admins to be able to see the log entry.
