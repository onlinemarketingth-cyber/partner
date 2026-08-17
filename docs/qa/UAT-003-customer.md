# UAT-003: Customer (Client, ClientDocument — PDPA)

Run after `php artisan migrate` (if new tables aren't applied yet) and
`php artisan db:seed` (idempotent — safe even if you already seeded
Phase 1/2). Backend on :8010, Agent Portal on :5173. This phase adds
no Admin screen — see TASK-004's "Out of scope".

## 1. Automated tests (run first)

- [ ] `php artisan test --filter=Customer` — all of ClientTest, ClientDocumentTest pass
- [ ] `./vendor/bin/pint --test` — clean
- [ ] If anything fails, stop and fix before manual UAT — same lesson as Phase 1/2 (an actual test run has caught a real bug in every phase so far that static review missed).

## 2. Agent ownership — the core new behavior this phase (agent@thailife.test)

This is the part that's new here — Client is the first domain where an Agent doesn't see everything company-wide:

- [ ] Log into the Agent Portal, go to "ลูกค้า" (Clients). Confirm you see only the 2 seeded clients referred by this agent (not any other agent's clients, if you've created more via a second agent account).
- [ ] Click "+ ลูกค้าใหม่", fill in name + phone, submit. Confirm the new client appears in the list immediately without a full reload.
- [ ] Confirm you did **not** get asked to pick a "referring agent" anywhere in this form — it's always forced to yourself.

## 3. PDPA — consent + health notes (agent@thailife.test)

- [ ] Create a client with the "ลูกค้าให้ความยินยอม (PDPA)" checkbox ticked, and some text in the health notes field. Submit.
- [ ] Open that client's detail drawer — confirm the health notes text displays correctly (this proves round-trip encrypt/decrypt works, not just that it saved).
- [ ] Ask someone with direct MySQL access (or use `php artisan tinker` → `DB::table('clients')->latest()->first()->health_notes`) to confirm the raw stored value is NOT the plaintext you typed — it should look like encrypted ciphertext.

## 4. Documents — upload/download (agent@thailife.test)

- [ ] Open a client's detail drawer, upload a small PDF or JPG via the upload control. Confirm it appears in the document list with a readable filename and size.
- [ ] Click the download icon next to it — confirm the file actually downloads with its original filename (not a UUID or generic name).
- [ ] Try uploading a `.exe` or `.zip` file — confirm it's rejected with a clear error, not silently ignored.
- [ ] Try uploading a file larger than 10MB — confirm it's rejected.

## 5. Cross-agent isolation (needs a second agent account — create one via tinker/seeder if you don't have one, e.g. `agent2@thailife.test`)

- [ ] Log in as `agent2`. Confirm their Clients list does NOT include the clients created by `agent@thailife.test` in steps 2-4.
- [ ] Using Postman/Insomnia/curl as `agent2`, `GET /api/v1/clients/<id of agent1's client>` — confirm **403 Forbidden** (not 404 — this is the same-company-wrong-agent case, different from cross-tenant).
- [ ] As `agent2`, try `POST /api/v1/clients/<agent1's client id>/documents` with a file — confirm **403**.
- [ ] As `agent2`, try `GET /api/v1/client-documents/<id of a document belonging to agent1's client>/download` — confirm **403**, and confirm no file bytes are returned.

## 6. Cross-tenant isolation (needs a second company — reuse or create one from an earlier phase's UAT)

- [ ] As an admin/agent in a different company, `GET /api/v1/clients/<id of a Thai Life client>` — confirm **404** (TenantScope filters the record out before the Policy even runs — this should read as "not found", not "forbidden").

## 7. Company Admin visibility (admin@thailife.test)

- [ ] As `admin@thailife.test`, `GET /api/v1/clients` via Postman/Insomnia (no Admin UI screen for this yet) — confirm the response includes clients referred by **every** agent in the company, not just one agent's.
- [ ] As `admin@thailife.test`, `DELETE /api/v1/clients/<id>` — confirm it succeeds (Company Admin can delete; Agent cannot per step 2's UI, which has no delete button at all).

## Known gaps at this stage (not bugs — out of scope per TASK-004)

- No Admin-side Clients screen yet — Company Admin can reach every client via the API, but there's no dedicated UI page. Add if needed.
- No edit-client form in the UI (API supports `PUT /clients/{id}`).
- Client + Product + Referral don't connect yet — that's Phase 4 (Referral & Pipeline). This phase is a standalone client roster + document manager.

## Sign-off

- [ ] Tested by: _____________  Date: _____________
- [ ] Result: ☐ Pass  ☐ Pass with known gaps above  ☐ Fail (list blocking issues)
