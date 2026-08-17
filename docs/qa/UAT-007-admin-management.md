# UAT-007: Admin — Manage Agents, Manage Companies, Gamification Config

Run after `php artisan migrate`. No new seed data this phase (Manage
Companies/Manage Agents create their own test data live through the
UI). Backend on :8010, Admin app on whatever port it's configured for
(check `frontend-admin/.env`). This phase adds no changes to the Agent
Portal at all.

**No email system, by design (human-confirmed) — read this first:**
creating an agent or resetting a password both require the Admin to
type a temporary value directly into the form and tell the agent what
it is out of band (phone call, in person, etc.). This is not a bug or
an oversight — do not report "no invite email was sent" as an issue.

## 1. Automated tests (run first)

- [ ] `php artisan test --filter=Platform` — all 22 tests pass across `CompanyTest` (8) and `UserManagementTest` (14)
- [ ] `./vendor/bin/pint --test` — clean
- [ ] If anything fails, stop and fix before manual UAT.

## 2. Manage Companies (superadmin@example.test only)

- [ ] Log in as the Super Admin — confirm "จัดการบริษัท" card appears on the Admin dashboard.
- [ ] Log in as `admin@thailife.test` (Company Admin) — confirm the "จัดการบริษัท" card is NOT shown, and navigating to `/companies` directly redirects back to the dashboard (client-side UX guard).
- [ ] As Super Admin, create a new company (name + slug). Confirm it appears in the list with "0 ผู้ใช้งาน".
- [ ] Try creating a second company with the same slug — confirm it's rejected (422, duplicate slug).
- [ ] Toggle a company's active status on/off — confirm the label updates.
- [ ] Confirm Thai Life (the seeded company) shows its actual user count correctly.

## 3. Manage Agents (admin@thailife.test)

- [ ] Go to "จัดการตัวแทน" — confirm the seeded `agent@thailife.test` appears, showing their cert status (ผ่าน Basic แล้ว / ยังไม่ผ่าน Basic depending on current seed state).
- [ ] Create a new agent: name, email, a temporary password (8+ characters), role = Agent. Confirm it appears in the "ใช้งานอยู่" tab immediately.
- [ ] Log in as that new agent (Agent Portal, different tab/browser) using the temp password you just set — confirm login succeeds.
- [ ] Back in Admin, change that agent's role dropdown to "Company Admin" — confirm it updates. Change it back to "Agent".
- [ ] Click "รีเซ็ตรหัสผ่าน" on that agent, type a new temporary password, save — confirm the OLD password no longer logs the agent in and the NEW one does.
- [ ] Click "ปิดใช้งาน" (deactivate) on that agent — confirm they move to the "ปิดใช้งาน" tab, and that they can no longer log in to the Agent Portal (login should fail — deactivated = soft-deleted, Sanctum auth won't find an active row).
- [ ] Click "กู้คืน" (restore) on the deactivated agent — confirm they move back to "ใช้งานอยู่" and can log in again.
- [ ] Try clicking "ปิดใช้งาน" on your OWN admin row (if it appears in the list) — confirm this is blocked (403) — self-lockout prevention.

## 4. Security & scoping checks (Postman/Insomnia/curl)

- [ ] As `agent@thailife.test`, `GET /api/v1/users` — confirm **403** (agents cannot manage other users at all).
- [ ] As `admin@thailife.test`, try creating a user with `"role": "super_admin"` — confirm **422**.
- [ ] As `admin@thailife.test`, try updating an existing agent with `"role": "super_admin"` — confirm **422** (this exact case was a gap caught by structural review and specifically tested — please double check it for real).
- [ ] If you have a second company's admin account (or create one via Super Admin), confirm they cannot see or edit `admin@thailife.test`'s agents — cross-company `GET /users/<id>` should be **404**.
- [ ] As `admin@thailife.test`, try `GET /companies/<some other company's id>` — confirm **403** (not 404 — `companies` isn't tenant-scoped, so this is a Policy rejection specifically, both are valid per CLAUDE.md §5 rule 5).

## 5. Gamification Config (admin@thailife.test)

- [ ] Go to "ตั้งค่า Gamification" → "อัตรา XP" tab — confirm the platform-default rules from Phase 6's seeder appear (marked "ค่า default ทั้งแพลตฟอร์ม").
- [ ] Create a company-specific override for one event type (e.g. "เรียนจบโมดูล") with a different XP value — confirm it appears as a second row without the platform-default label.
- [ ] Try creating a SECOND active rule for the same event type in your company — confirm it's rejected (an active-rule-uniqueness guard already existed from Phase 6, this screen just surfaces it).
- [ ] Go to the "Badge" tab — confirm the 3 seeded badges appear. Award one to an agent. Confirm it appears in "ประวัติการมอบ" below.
- [ ] Award the same badge to the same agent again — confirm it succeeds without creating a duplicate entry (idempotent, from Phase 6).
- [ ] As `agent@thailife.test`, check the Agent Portal's "Leaderboard" page — confirm the badge you just awarded appears there.

## Known gaps at this stage (not bugs — out of scope per TASK-009)

- No cascading effect when deactivating a Company — its users can still log in. Not defined anywhere in CLAUDE.md, flagged not invented.
- No way to move a user to a different company.
- No way to create a new Super Admin account through the UI — this remains a manual/database-level action.
- No bulk agent import.
- Password reset/creation is manual-temp-password only — no email delivery.

## Sign-off

- [ ] Tested by: _____________  Date: _____________
- [ ] Result: ☐ Pass  ☐ Pass with known gaps above  ☐ Fail (list blocking issues)
