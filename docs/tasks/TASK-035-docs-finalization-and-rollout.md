Task: Docs finalization + rollout guidance
Owner: ag-lead
Goal: Close out the ADR-011 initiative — update ADR-011's status from "Accepted" to reflect final built shape (if anything shifted during implementation), update OpenAPI spec and SETUP.md, and write rollout guidance for enabling each new plan type safely on a live multi-tenant platform.
Related: ADR-011 (all sections), CLAUDE.md Section 9 (Definition of Done), Section 10 (handoff principle)
Input:
  - Completed TASK-027 through TASK-034.
Expected output:
  - `/docs` OpenAPI spec updated with every new/changed endpoint from TASK-027 through TASK-032.
  - `SETUP.md` entry describing the new migrations/seeders needed for a fresh environment to have the new plan types available (mirrors how ADR-009's SETUP.md entry was written).
  - ADR-011 amended (not rewritten) with a short "Implementation Notes" section if any decision changed shape during building (e.g. a config field name, an enum value) — ADRs are a historical record, so amend forward rather than silently editing prior sections.
  - Rollout guidance: since existing companies default to `NULL` on every new nullable column (backward-compatible by construction per every task above), document that enabling a new plan type for a live company is opt-in and reversible (switching back to Unilevel/existing behavior by clearing the override), and flag that Binary/Matrix/Stairstep/Generation each need at least one seed `agent_rank`/`commission_binary_settings`/etc. row configured by a Company Admin before that plan type can actually calculate anything — the system should fail closed (clear error, not silent zero-commission) if a company selects a plan type before configuring its required settings.
Acceptance Criteria:
  - OpenAPI spec accurately reflects the shipped API (verified by ag-lead against ag-dev's actual routes, not copied from the task specs).
  - Rollout guidance explicitly confirms backward compatibility for every existing company (no plan-type change without an explicit admin action).
  - "Fail closed, not silent zero" behavior for an under-configured plan type is verified against ag-dev's actual implementation, not assumed.
Out of scope: Any new feature work — this is documentation and verification only.
Depends on: TASK-034
Blocks: none (final task in this initiative)
