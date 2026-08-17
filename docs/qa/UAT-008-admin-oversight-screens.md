# UAT-008: Admin — Clients, Referral & Pipeline, Commission oversight

No backend changes this phase — no new migrations/seeders to run
beyond what earlier phases already required. Backend on :8010, Admin
app on whatever port `frontend-admin/.env` points to.

## 1. Clients (admin@thailife.test)

- [ ] Go to "ลูกค้า" — confirm you see every client in the company (not just ones you personally referred), each showing which agent referred them.
- [ ] Click a client — confirm the detail drawer shows phone/email/health notes and any uploaded documents.
- [ ] Download a document — confirm it downloads correctly (same authenticated-download mechanism as the Agent Portal, never a public link).
- [ ] Confirm there is no "add client" or "upload document" button here — that remains an Agent Portal-only action.

## 2. Referral & Pipeline (admin@thailife.test)

- [ ] Go to "Referral & Pipeline" — confirm you see every referral in the company across all agents, each row showing which agent it belongs to.
- [ ] Filter by stage tab (e.g. "รอนัดหมาย") — confirm the list narrows correctly.
- [ ] Click "ขั้นถัดไป" on a referral — confirm it advances exactly one stage (same sequential-only behavior as the Agent Portal).
- [ ] Click a referral to open its audit trail drawer — confirm the full who/when/from→to history appears.

## 3. Commission (admin@thailife.test)

- [ ] Go to "Commission" — confirm you see every commission entry in the company, not just one agent's.
- [ ] Confirm the "รอจ่าย" (pending) tab is selected by default and the "จ่ายแล้ว" button only appears on pending rows.
- [ ] Click "จ่ายแล้ว" on a pending entry — confirm it moves to the "จ่ายแล้ว" tab and the button disappears for that row.
- [ ] As `agent@thailife.test` (a different session/browser), confirm `POST /api/v1/commission-ledger/<id>/mark-paid` on their own entry still returns 403 — this screen doesn't change that existing restriction.

## Known gaps at this stage (not bugs — out of scope per TASK-010)

- No way to create a Client or upload a document from the Admin app.
- No bulk "mark paid" — one entry at a time only.
- No filtering/search beyond the existing stage/status tabs.

## Sign-off

- [ ] Tested by: _____________  Date: _____________
- [ ] Result: ☐ Pass  ☐ Pass with known gaps above  ☐ Fail (list blocking issues)
