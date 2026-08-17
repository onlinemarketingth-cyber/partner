# UAT-004: Referral & Pipeline

Run after `php artisan migrate` (if new tables aren't applied yet) and
`php artisan db:seed` (idempotent — safe even if you already seeded
Phase 1-3). Backend on :8010, Agent Portal on :5173. This phase adds no
Admin screen — see TASK-006's "Out of scope".

**Before you start:** `ReferralSeeder` only creates sample referrals if
`agent@thailife.test` has already passed the Basic certification exam
for real (via UAT-002 §3). If you skip that first, `db:seed` will print
a message saying it skipped Referral seeding, and step 2 below will
show an empty list until you either complete UAT-002 §3 yourself or
follow step 1 to unlock the gate.

## 1. Automated tests (run first)

- [ ] `php artisan test --filter=Referral` — all of `ReferralTest` (9 tests) and `PipelineTest` (7 tests) pass
- [ ] `./vendor/bin/pint --test` — clean
- [ ] If anything fails, stop and fix before manual UAT — every phase so far has had at least one real bug only surface once this actually ran.

## 2. BR-1 gate — the part that matters most (agent@thailife.test)

- [ ] Log into the Agent Portal, go to "SWS Referral". If this agent hasn't passed Basic cert yet, the "+ Referral ใหม่" button is disabled/grey, and an amber banner explains why (BR-1) — confirm this, don't skip it.
- [ ] Go to Academy, complete the Basic exam for real (same steps as UAT-002 §3) if you haven't already.
- [ ] Return to SWS Referral — the button should now be enabled with no page reload needed beyond a normal navigation (KPIs/cert-status re-fetch on mount).

## 3. Submitting a referral (agent@thailife.test, after passing Basic)

- [ ] Click "+ Referral ใหม่". If you have no clients yet, the form shows a note pointing you to the Clients page first — go add one via UAT-003 if needed.
- [ ] Fill in ลูกค้า (your own client), แพ็กเกจ, สาขา, เวลาที่สะดวก. Submit.
- [ ] Confirm the new referral appears in the list immediately (no reload) at "Complete Registered" stage, and the KPIs (ทั้งหมด/รอดำเนินการ/เดือนนี้) update.

## 4. Pipeline — advancing stages (agent@thailife.test)

- [ ] Go to "Pipeline". The referral you just submitted should appear under the "Complete Registered" tab (and under "ทั้งหมด").
- [ ] Click "ขั้นถัดไป" — confirm the stage badge updates to "Waiting Appointment" without a page reload, and the referral moves to the correct tab.
- [ ] Repeat 3 more times — confirm it moves through Finish 1st Doctor Meeting → Complete Payment → Ongoing Next Meeting, in that exact order, one step at a time (never able to skip).
- [ ] Once at "Ongoing Next Meeting", click a row to open its detail drawer — confirm it shows "นัดหมายครั้งที่ 2". Click "ขั้นถัดไป" from the list again — confirm it becomes "นัดหมายครั้งที่ 3", then "4" on a further click.

## 5. Audit trail (agent@thailife.test)

- [ ] Click any referral row (not the "ขั้นถัดไป" button itself) to open its detail drawer — confirm it shows a chronological (newest-first) list of every stage change so far, each with "who" and "when", including the very first entry from when the referral was created (which shows no "from" stage, just the initial stage).

## 6. Security checks (the ones a UI click-through won't naturally catch — use Postman/Insomnia/curl)

- [ ] As the agent, `POST /api/v1/referrals` with someone else's `client_id` (a colleague's client) — confirm **422** with a `client_id` validation error, not a silent success.
- [ ] As the agent, `POST /api/v1/referrals` with an `agent_id` field set to any value — confirm **422** (Agents may never send this field at all, even their own ID).
- [ ] As the agent, `POST /api/v1/referrals/<id>/advance` with a body like `{"to_stage": "complete_payment"}` while the referral is still at "Complete Registered" — confirm it only moves one step to "Waiting Appointment", the body is completely ignored.
- [ ] Using a second agent account (create one if needed, e.g. `agent2@thailife.test`), try to view or advance the first agent's referral directly by ID — confirm **403** both times (same-company-wrong-agent, not 404).
- [ ] As an admin/agent in a different company, try to view a Thai Life referral by ID — confirm **404**.

## 7. Company Admin (admin@thailife.test, via API — no Admin UI screen yet)

- [ ] `GET /api/v1/referrals` as `admin@thailife.test` — confirm it returns referrals from every agent in the company, not just one.
- [ ] `POST /api/v1/referrals` as admin, with a valid `agent_id` for an agent who has NOT passed Basic — confirm **422** (BR-1 is enforced against the agent being referred-for, not just the actor submitting).
- [ ] Repeat with an `agent_id` for an agent who HAS passed Basic — confirm **201**.

## Known gaps at this stage (not bugs — out of scope per TASK-006)

- Reaching "Complete Payment" does **not** yet create a Commission Ledger entry (BR-4) — that's the next phase. Don't expect to see any commission data change here.
- No Admin-side Referral/Pipeline screen yet — Company Admin can reach everything via the API (step 7 above), but there's no dedicated UI page.
- No hard cap on how many times "Ongoing Next Meeting" can advance (nothing stops a 5th, 6th, etc. click) — CLAUDE.md's "2nd → 3rd → 4th" phrasing wasn't treated as a stated limit; flag to a human if you believe there should be one.
- No way to edit or cancel a submitted referral from the UI (or the API) — once submitted, it only moves forward.

## Sign-off

- [ ] Tested by: _____________  Date: _____________
- [ ] Result: ☐ Pass  ☐ Pass with known gaps above  ☐ Fail (list blocking issues)
