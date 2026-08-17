# UAT-011: Move user between companies

No new migrations (uses the existing `audit_logs` table, first real writer).

## 1. Move a user (Super Admin only)

- [ ] Go to "จัดการตัวแทน" as a Super Admin — confirm each agent row shows its company name and a "ย้ายบริษัท" button.
- [ ] Click "ย้ายบริษัท" on an agent, select a different company from the dropdown, confirm.
- [ ] Confirm the agent's row now shows the new company name.
- [ ] Log in as that agent — confirm they now see the new company's products/clients/etc. (TenantScope follows the updated company_id).

## 2. Historical data is preserved

- [ ] Before moving, note an agent's existing commission ledger entries and XP total (Leaderboard) for their OLD company.
- [ ] Move them to a new company.
- [ ] As the OLD company's admin, confirm those historical commission/XP entries still show up under a leaderboard/report scoped to the old company (they were NOT deleted or reassigned).
- [ ] As the NEW company's admin, confirm the moved agent starts with zero history in the new company (nothing was copied over).

## 3. Access control

- [ ] As a Company Admin (not Super Admin), confirm "ย้ายบริษัท" does not appear in the UI, and `POST /users/{id}/move-company` returns 403 if called directly.
- [ ] Confirm a Super Admin cannot move another Super Admin account (not applicable via UI since Super Admins aren't listed here, but flagged as a backend rule).

## 4. Audit trail

- [ ] Confirm each move creates an audit_logs row (action `move_to_company`) — inspect via `tinker`/DB directly (no Admin UI screen for audit logs exists yet).

## Known gaps at this stage (not bugs — out of scope per TASK-013)

- No Admin UI to browse the audit log itself yet (only this action + Section 4.3's pipeline stage log have any audit-trail writers so far).
- No bulk move.
- Referred Clients/open Referrals stay associated with the company they were created under — not reassigned along with the user.

## Sign-off

- [ ] Tested by: _____________  Date: _____________
- [ ] Result: ☐ Pass  ☐ Pass with known gaps above  ☐ Fail (list blocking issues)
