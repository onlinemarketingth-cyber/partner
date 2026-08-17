# ERD-001: Full Database Schema Proposal — Sync Vision Agent

- **Date:** 2026-07-09 (rev. 3 — Agent and Customer promoted to peer domains alongside Product)
- **Status:** Draft — for human review, not yet implemented
- **Author:** ag-lead

This is a design-for-review document per the human's request: cover
every business domain in CLAUDE.md with a schema proposal before
ag-dev writes any migrations. Nothing here is built yet — only
`companies` and `users` exist today (TASK-001). Every table below
carries `company_id` unless explicitly noted "global", per Section 5
rule 1 (BR-6, highest priority). No BR-7 values (rates, prices,
syllabus content, XP amounts) are hardcoded anywhere — those are
columns to be seeded/edited later, not decided here.

**Rev. 3 changes (human-directed):** Agent is now its own domain box,
peer-level with Product catalog, instead of being buried as a footnote
inside Tenancy — it's what the whole Agent Portal frontend is built
around. **Agent is still not a new table** — it's `users` filtered to
`role = agent`, same as before (see the standing decision in Section
1). Customer is also pulled out as its own peer domain — previously
folded into "Clients, SWS Referral & Pipeline," which hid the fact
that a customer is a distinct party, separate from the referral/pipeline
process that connects them to an Agent and a Product. The `role` enum
(agent / company_admin / super_admin) stays listed in Tenancy too,
since company_admin/super_admin are still just role values on the same
`users` table and matter for tenancy-level access, not just the Agent
Portal.

**Rev. 2 changes:** Product catalog got Brand and Category (previously
a flat `packages` table). Academy became downstream of Product catalog
— modules teach agents about a specific product.

---

## 1. Tenancy & Identity (built — TASK-001)

**companies** — id, name, slug, is_active, timestamps, soft-deletes.

**users** — id, company_id (FK, nullable for super_admin), name, email,
password, **role** (enum, `App\Enums\UserRole`), timestamps,
soft-deletes. `TenantScope` global scope, exempted for super_admin.

> **`role` is the 3-value enum that drives every visibility rule in
> this document** (Section 5): `agent`, `company_admin`, `super_admin`.
> One column on one unified `users` table — not separate account
> types (confirmed in chat earlier: "ใช้ admin ตัวเดียวกัน ต่างกันแค่สิทธิ์").
> `company_admin`/`super_admin` are administrative — they drive the
> separate Admin frontend (ADR-003). `agent` is what Section 2 below
> exists to call out on its own.

---

## 2. Agent (BR-1, BR-2, BR-4, BR-5) — peer domain to Product, not a new table

**No new table.** "Agent" = `users` where `role = agent`. It gets its
own section here — instead of staying a one-line mention under
Tenancy — because it's the actor at the center of almost everything
below: the Agent Portal frontend is built entirely around this role,
and `agent_id`/`user_id` FKs pointing back to this same `users` row
appear in Academy (certifications), Referral & Pipeline (who
submitted/owns a referral), Commission Ledger (who earns), and
Gamification (who levels up).

If a real need for agent-only columns emerges later (e.g. a phone
number or license number that only agents have, never company_admins),
that's a `// TODO: CONFIRM` for a future revision — not guessed here.
For now, `users` already carries everything an agent record needs.

---

## 3. Product Catalog: Brand, Category & Products (BR-2, BR-3, BR-7)

| Table | Key columns | Notes |
|---|---|---|
| **brands** | id, company_id (FK), name, logo_path (nullable), is_active, timestamps | Per-company — each company can carry multiple brands. |
| **product_categories** | id, company_id (FK), name, sort_order, is_active, timestamps | Modeled as **independent of brand** (a category isn't nested under one brand) — `// TODO: CONFIRM` if you actually want category nested under brand instead; independent is the more common catalog pattern and easy to change before any data exists. |
| **products** | id, company_id (FK), brand_id (FK), category_id (FK), name, price_satang (**integer**, BR-3), description, is_active, timestamps | CLAUDE.md's glossary already uses "Package / Product" as synonyms. The 8,900/9,900 THB figures are seed data here, not code (BR-7, still "to be confirmed"). |
| **commission_rules** | id, company_id (FK), cert_tier_id (FK), product_id (FK), rate_type (percentage/fixed_satang), rate_value, effective_from, effective_to (nullable) | BR-2: "rate = cert tier × package sold... from `commission_rules` config — never hardcode." `effective_from/to` lets rates change over time without touching historical ledger entries. |

---

## 4. Academy / Certification (BR-1) — downstream of the Product catalog

Gates SWS Referral + Pipeline access for an **Agent** — an agent needs
a `basic` `user_certifications` row before those features unlock.
Academy content exists **to teach agents about specific products**,
not just abstract cert-tier syllabus.

| Table | Key columns | Notes |
|---|---|---|
| **cert_tiers** | id, key (basic/intermediate/high), name, sort_order, is_mandatory | **Global** (no company_id) — tiers described in CLAUDE.md §2 as platform-wide, not per-company. `// TODO: CONFIRM` if a company ever needs custom tiers. |
| **modules** | id, cert_tier_id (FK), product_id (FK, nullable), title, content_type (video/pdf/quiz/link), content_ref, sort_order, xp_reward, is_published | `product_id` — a module can teach about one specific product (e.g. "Intro to [Product X]"); nullable so general/non-product modules (onboarding, compliance) still fit. Syllabus content itself is still BR-7 "to be confirmed." |
| **module_completions** | id, user_id (FK → Agent), module_id (FK), completed_at, score (nullable) | One row per agent per module. |
| **exams** | id, cert_tier_id (FK), title, passing_score, config (json) | `// TODO: CONFIRM` exam engine/question format — not specified in CLAUDE.md. |
| **exam_attempts** | id, user_id (FK → Agent), exam_id (FK), score, passed (bool), attempted_at | Multiple attempts allowed; latest pass is what matters. |
| **user_certifications** | id, user_id (FK → Agent), cert_tier_id (FK), passed_at, exam_attempt_id (FK, nullable) | **This is the BR-1 gate record.** A `basic` row here = SWS Referral/Pipeline unlocked. Checked at both API (Policy) and UI (router guard) per BR-1. |

---

## 5. Customer (CLAUDE.md §2 "Client", PDPA) — peer domain to Agent and Product

The end customer an Agent refers into the system to buy a Product.
Distinct from Agent: a Customer never logs in, has no `role`, and
lives in its own table rather than on `users`.

| Table | Key columns | Notes |
|---|---|---|
| **clients** | id, company_id (FK), referring_agent_id (FK → Agent/users), name, phone, email (nullable), consent_given_at, health_notes (**encrypted cast**, nullable), timestamps, soft-deletes | PDPA-sensitive (Section 6). `// TODO: CONFIRM` exact health-data fields actually collected and the consent flow. Never truly hard-deleted (soft-deletes). |
| **client_documents** | id, client_id (FK), company_id (FK), uploaded_by_user_id (FK → Agent), file_path (tenant-scoped, never public), original_filename, mime_type, size_bytes, timestamps | Section 5 rule 6 — uploaded client files. Downloads must go through an access-checked controller action, never a direct public URL. |

---

## 6. Referral & Pipeline (CLAUDE.md §4.3) — the transaction connecting Agent, Customer & Product

This is where the three peer domains above (Agent, Customer, Product)
actually meet: an Agent submits a referral **for** a Customer **to
buy** a Product, then moves it through the pipeline stages.

| Table | Key columns | Notes |
|---|---|---|
| **referrals** | id, client_id (FK → Customer), agent_id (FK → Agent), product_id (FK → Product), branch, preferred_time (datetime), current_stage (enum, see §4.3), meeting_number (nullable int), submitted_at | One client can have multiple referrals over time (repeat purchase). `meeting_number` (2/3/4...) only applies when stage = `ongoing_next_meeting`. |
| **pipeline_stage_logs** | id, referral_id (FK), from_stage (nullable), to_stage, changed_by_user_id (FK → Agent), changed_at | BR-4.3: "every status change must be recorded in audit log (who, when, from→to)." Also where a Service validates only forward/sequential transitions are allowed. |

---

## 7. Commission Ledger (BR-4)

| Table | Key columns | Notes |
|---|---|---|
| **commission_ledger** | id, company_id (FK), agent_id (FK → Agent), referral_id (FK), cert_tier_id_at_time (FK), product_id (FK → Product), rate_applied, amount_satang (integer, BR-3), payment_status (pending/paid), paid_at (nullable), created_at | **Immutable** (BR-4) — created once when the trigger condition fires (default: `Complete Payment` stage, unless config says otherwise), never edited after. `payment_status`/`paid_at` are the one explicitly-allowed mutable field per BR-4. Historical reports always read from here, never recomputed live. |

---

## 8. Gamification (BR-5, BR-7)

| Table | Key columns | Notes |
|---|---|---|
| **gamification_rules** | id, company_id (FK, nullable = platform default), source_type (module_completed/exam_passed/referral_submitted/pipeline_stage_advanced/payment_complete), xp_value, is_active | BR-5 config — XP amounts are never hardcoded in a Service. |
| **xp_ledger** | id, user_id (FK → Agent), source_type, source_id (nullable, polymorphic ref), xp_awarded, created_at | Immutable log, same pattern as commission_ledger. Agent's total XP = `SUM(xp_awarded)`, never stored/duplicated on the user row. |
| **level_thresholds** | id, level_number, xp_required | Config table — avoids hardcoding level breakpoints. |
| **badges** | id, company_id (FK, nullable = platform default), key, name, description, icon, condition_config (json) | `// TODO: CONFIRM` badge conditions — not specified in CLAUDE.md. |
| **user_badges** | id, user_id (FK → Agent), badge_id (FK), earned_at | |

---

## 9. Audit Log (Section 6)

**audit_logs** — id, company_id (FK, nullable), actor_user_id (FK,
nullable for system actions), action, auditable_type, auditable_id,
old_values (json), new_values (json), ip_address, created_at.

Generic polymorphic trail for anything Section 6 requires logging:
money, commission, status, certification, or permission changes.
`pipeline_stage_logs` above is a specialized version of this same
concept, kept as its own table since it's queried often enough on its
own to deserve a dedicated, narrower shape.

---

## Open questions (`// TODO: CONFIRM`) — need your input before ag-dev builds this

1. **Brand ↔ Category relationship** — modeled as independent dimensions on `products` (both FK directly on the product). Say the word if you want Category nested under Brand instead.
2. **cert_tiers / exams / badges scope** — global (platform-wide) vs. per-company. Proposed: global for now, easy to add `company_id` later.
3. **Agent-only columns** — does an Agent need fields `users` doesn't already have (license number, phone, etc.)? Proposed: none yet, `users` is sufficient.
4. **Health-data fields on `clients`** — what actually needs to be collected/encrypted? Proposed schema only has a placeholder `health_notes` field.
5. **Consent flow** — what does "requires consent" (Section 6, PDPA) actually require capturing beyond a timestamp?
6. **Exam engine shape** — multiple choice? pass/fail only? `config` json is a placeholder.
7. **`meeting_number` vs. separate stage rows** for "Ongoing Next Meeting (2nd → 3rd → 4th)" (BR-4.3).
8. **Notifications** — frontend has a visual-only `NotificationBell.vue` stub, but this project's CLAUDE.md doesn't specify a notification system's requirements. Flagging rather than inventing a schema.
9. **Badge condition format** — `condition_config` is a placeholder json blob.

None of these block starting on the Agent, Product Catalog, or Academy
migrations, since those are structurally clear now — they only block
the specific fields called out above.

## Related

- CLAUDE.md §2 (Domain Glossary), §4 (BR-1..BR-5), §4.3 (Pipeline),
  §5 (Multi-Tenancy), §6 (Security/PDPA)
- `docs/tasks/TASK-001-multitenant-foundation.md` (companies/users,
  already built)
