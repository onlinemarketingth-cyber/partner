# ADR-013: Client File — Multi-agent Surfacing, National ID (PDPA), Search, Full Page

- **Date:** 2026-07-23
- **Status:** Accepted — human-confirmed 2026-07-23 (4 decisions + "ทำทุก phase ไม่ต้องรอถามให้เสร็จ"). Implemented this session; **migration + backend tests must be run by the human** (sandbox has no PHP runtime).
- **Author:** ag-lead
- **Related:** CLAUDE.md §2 (Client/SWS Referral/Pipeline), §4.3 (pipeline state machine), §5 (tenant isolation), §6 (PDPA / sensitive data), BR-7. Builds on ADR-012 (Sales IA). TASK-049.

## Context

The human asked to turn the Admin client detail view into a proper "client registry file" (แฟ้มทะเบียนลูกค้า, medical-record feel), with four explicit requirements: (1) make the **selling agent prominent per product of interest**; (2) support **one client → many agents** — clarified as "a product not yet closed can be worked by multiple agents in turn" (Agent A can't close, client later buys via Agent B); (3) show **which agent sold each product + a viewable log + the sales process**; (4) add **search by name / phone / national ID / email** on the list.

Investigation of the current schema found: `clients` has a single `referring_agent_id`, a single `name` column, **no national_id**, and **no search** on `/clients`. Crucially, each **Referral already carries its own `agent_id`** (and `co_agent_id` + split), and the `referrals` table has **no unique(client_id, product_id)** constraint — so multiple agents' referrals for the same client (even the same product) are already permitted at the DB level.

## Decisions (all human-confirmed)

1. **Multi-agent = referral-based, no new pivot table.** "One client → many agents" is expressed through the client's existing referrals, each carrying its own selling agent. `referring_agent_id` stays as the "first-contact/owner" agent. The Client File surfaces the seller per referral (backend now eager-loads `referrals.agent`/`coAgent`; `ReferralResource` already exposed `agent`). No schema change to `referrals`. The competing-agents scenario needs no constraint change — the schema already allows it.
2. **National ID added as sensitive PDPA data (§6).** New `clients.national_id` (`encrypted` model cast, at-rest, same pattern as `health_notes`/`users.bank_account_number`) plus a **deterministic blind index `clients.national_id_hash`** (HMAC-SHA256 of digits-only, keyed by APP_KEY), kept in lockstep via a model `saving` hook. Validated by a new `App\Rules\ThaiNationalId` (13-digit modulo-11 checksum). **Trade-off accepted & documented:** an encrypted column can't be LIKE-searched, so national-ID search is **exact-match only** (full 13 digits) via the hash — partial search is impossible by design. The hash is indexed with `company_id` for tenant-scoped lookup.
3. **National ID access gating.** `ClientResource` always exposes `national_id_masked` (last 4 digits); the **full decrypted `national_id` is exposed only to a privileged viewer** — Super Admin, the client's own Company Admin, or the referring agent who captured it. A second/competing agent sees only the mask. Encryption-at-rest alone is insufficient (it decrypts into the array), so the Resource does its own gating, mirroring the `bank_account_number` reasoning.
4. **Search on `/clients`.** `q` = free-text LIKE across name/phone/email (partial). `national_id` = exact via the blind index. Both respect the existing TenantScope + Agent "own-referred-only" narrowing.
5. **Name stays a single `name` column** (human choice) — free-text search covers first/last within it; no split, no data migration.
6. **Full-page Client File** (human choice) — new `frontend-admin` route `client-file` at `/clients/:id` (`ClientFileView.vue`); the list row navigates there and the old detail drawer is removed. Sections: registration/identity (incl. masked/full national ID), products-of-interest with prominent seller + co-agent + split + a lazy-loaded stage-log **timeline** (the sales process/audit "log", reusing `GET /referrals/:id/stage-logs`) + "ดูใน Pipeline" cross-link, an "agents involved" dedup chip list, activity log, and documents.
7. **National ID entry** added to the Agent Portal (`frontend/ClientsView.vue`) create form — that is where clients are created; the Admin file is read-only.

## Consequences

- **Positive:** Client File now reads like a registry; multi-agent is visible without schema churn; national ID is captured with proper PDPA handling (encrypt + mask + role-gated + checksum-validated) and is searchable within the documented exact-match limit.
- **Trade-off:** National-ID search is exact-only; users must type all 13 digits. Surfaced in the search UI ("ต้องตรงทั้งหมด").
- **Operational:** Requires `php artisan migrate` (`2026_07_23_170000_add_national_id_to_clients_table`) on the real DB before the feature works; run `php artisan test --filter=ClientNationalIdSearchTest`. Because the blind index is keyed by APP_KEY, **rotating APP_KEY invalidates existing national_id_hash values** — they would need re-derivation (backfill) after a key rotation. Noted for ops.

## Out of scope

- Agent-Portal-side multi-agent VISIBILITY rules (whether a competing agent should see another agent's dealings on a shared client) — the Admin file is Company-Admin-scoped, so this didn't arise here; left as a separate future decision.
- First/last name split; Kanban already shipped in ADR-012.
