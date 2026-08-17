Task: Customer — Client, ClientDocument (PDPA)
Owner: ag-dev (backend), ag-ui (Agent Portal screen) — executed directly by ag-lead, no separate ag-dev/ag-ui sessions running yet
Goal: Turn the ERD-001 rev. 3 "Customer" schema into a working feature where agents record the clients they refer, with PDPA-sensitive fields protected and Section 5 rule 4's "Agent sees only their own records" enforced for the first time in this codebase.
Related: Section 5 rule 4 (Agent visibility — own records only, first domain where this differs from company-wide read access), Section 5 rule 6 (uploaded files — tenant-scoped by path, access-checked before download, never a public URL), Section 6 (PDPA — consent, at-rest encryption, role-based access; also "never trust client input" — referring_agent_id), BR-6 (tenant isolation), ERD-001 §"Customer"

Input: Client/ClientDocument migrations + models (schema pass, rev. 3, already had `company()`/`referringAgent()`/`documents()`/`referrals()` relations and an `'encrypted'` cast on `health_notes`), Phase 1 seeded users (agent@thailife.test as the referring agent for seed data)

Expected output:
- Policies: ClientPolicy (view/update narrowed to Company Admin or the referring agent; delete Company Admin/Super Admin only), ClientDocumentPolicy (view mirrors parent Client, create always true — gated via the parent Client's own visibility check in the Controller, delete Company Admin/Super Admin only)
- Form Requests under app/Http/Requests/Customer/: StoreClientRequest, UpdateClientRequest, StoreClientDocumentRequest
- Services: ClientService (forces `referring_agent_id` to self for an Agent actor, regardless of what's submitted), ClientDocumentService (stores files under `client-documents/{company_id}/{client_id}/{uuid}.{ext}` on the private `local` disk, never a public URL)
- Resources: ClientResource, ClientDocumentResource (deliberately omits `file_path` — the only path to a file's bytes is the authenticated download route)
- Controllers + routes: full CRUD for Client (`authorizeResource`, `index()` additionally narrows the query by `referring_agent_id` for Agent actors); ClientDocument has index/store (nested under a client) plus direct download/destroy routes, each with an explicit `$this->authorize(...)` call (no `authorizeResource()` — the route parameter names don't match a single resource shape)
- CustomerSeeder (2 sample clients referred by agent@thailife.test — plain sample data, not BR-7-governed)
- Agent Portal: ClientsView.vue — client list (already narrowed server-side to the agent's own referrals), add-client form (name/phone/email/consent/health notes), detail drawer with document list + upload + authenticated download
- Feature tests: tests/Feature/Customer/{Client,ClientDocument}Test.php

Acceptance Criteria:
  - An Agent's `GET /api/v1/clients` returns only clients they referred, never the whole company's list (verified: test_agent_only_sees_their_own_referred_clients)
  - An Agent viewing a colleague's client (same company, different referring agent) gets **403**, not 404 — this is a different failure mode than cross-company access, which 404s via TenantScope filtering the route-model-binding before the Policy ever runs (verified: test_agent_cannot_view_a_colleagues_client vs. test_cross_tenant_client_access_is_404)
  - `referring_agent_id` submitted by an Agent is rejected at the validation layer (422), not merely overridden silently downstream — belt-and-braces per Section 6 (verified: test_agent_submitting_a_referring_agent_id_is_rejected_at_validation)
  - An Agent creating a client without submitting `referring_agent_id` at all always gets it set to themselves (verified: test_agent_creating_a_client_is_always_referred_to_themself)
  - An Agent cannot delete a client — Company Admin/Super Admin only (verified: test_agent_cannot_delete_a_client)
  - `health_notes` is unreadable as plaintext directly from the `clients` table (encrypted at rest) — verified by querying the raw column via `DB::table(...)`, bypassing Eloquent's cast (verified: test_health_notes_are_encrypted_at_rest)
  - A document upload is stored under a path containing both `company_id` and `client_id`, on the private `local` disk (not the public-symlinked disk) — no direct/public URL ever reaches the client (verified: test_agent_can_upload_a_document_to_their_own_client asserting the storage path)
  - Uploading a document to a colleague's client is rejected (403); downloading requires the same visibility check as the parent client (verified: ClientDocumentTest)
  - A disallowed file type (e.g. `.exe`) is rejected at validation (422) (verified: test_upload_rejects_a_disallowed_file_type)
  - No BR-7 value is hardcoded — the file type allow-list and 10MB size cap in StoreClientDocumentRequest are marked `// TODO: CONFIRM (product)` since they're a product decision, not yet a business rule in CLAUDE.md

Verification status:
  - Structural review passed via independent subagent (see prompt log) — one real gap found and fixed: `StoreClientRequest` accepted `referring_agent_id` into `$request->validated()` for an Agent even though `ClientService` always discarded it; fixed by adding `Rule::prohibitedIf(...)` so it's rejected at validation instead of relying solely on the Service to neutralize it. All other checks (Agent ownership scoping, PDPA encryption, file security, authorization wiring, factory correctness, seeder idempotency, recurrence of Phase 1/2's known bug classes) passed with no further issues.
  - Frontend (`frontend` — Agent Portal only; no Admin-side Clients screen yet, see Out of scope): `eslint . --cache` clean, `vue-tsc --build` type-check clean (exit 0), and `vite build` now confirmed clean too (exit 0, `dist/` produced including `ClientsView`'s chunk) — the earlier `Bus error` seen mid-session was a transient sandbox disk-space issue (native bundler mmap failing under low disk), not a code defect; resolved once /tmp was cleaned up, see TASK-005-ui-animation.md.
  - Backend: tests are WRITTEN, structurally reviewed, but **not yet run by the human**. Do not treat this phase as verified until `php artisan test --filter=Customer` has actually been run. See docs/qa/UAT-003-customer.md.

Out of scope (future tasks):
  - Admin-side Clients screen (`frontend-admin`) — Company Admin/Super Admin can already reach every client in their company via the API (Policy already allows it), but there's no dedicated Admin UI screen yet. Add if Admins need to browse/manage clients directly rather than only through Phase 4's Referral flow.
  - Full integrated Client + Product + Referral submission UI — belongs to Phase 4 (Referral & Pipeline), where SWS Referral actually connects Client + Agent + Product together. This phase's ClientsView.vue is a standalone client roster + document manager only.
  - Editing/updating a client from the UI (API supports `PUT /clients/{id}`, no edit form built yet) — add when needed.
  - The full PDPA consent flow beyond a single `consent_given_at` timestamp (ERD-001 open question — still unconfirmed, not guessed here).

Design notes (not in CLAUDE.md, decided here — flag if wrong):
  - `ClientDocumentPolicy::view()`/`ClientController`'s `index()`/`store()` treat "can I touch this client's documents" as identical to "can I view this client" — no separate finer-grained document permission exists yet, on the reasoning that documents are just an attachment of the client record, not an independently-governed resource.
  - File type allow-list (`pdf,jpg,jpeg,png`) and 10MB cap are a reasonable placeholder, not a business rule from CLAUDE.md — marked `// TODO: CONFIRM (product)` in StoreClientDocumentRequest.
