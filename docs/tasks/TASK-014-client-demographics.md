Task: Client demographic fields (date of birth, address/province, occupation)
Owner: ag-dev + ag-ui
Goal: A CRM-standards comparison (2026-07-13) identified date of birth, address/province, and occupation as commonly-tracked fields for an agent-referred customer — used for age-based package pricing (DOB), regional sales analytics (province), and risk context (occupation). Human confirmed: include in this sprint (previously deferred as "skip for now").
Related: CLAUDE.md §2 (Client), Section 6 (PDPA — general personal data, NOT the "sensitive" health category that already gets field-level encryption), BR-7 (province list is a fixed geographic fact, not an unfinalized business value — see Design notes)

Input: `clients` table (existing, TASK-004), `StoreClientRequest`/`UpdateClientRequest`/`ClientResource` (existing validation/exposure pattern)

Expected output:
- Migration: adds `date_of_birth` (date, nullable), `address` (text, nullable), `province` (string, nullable), `occupation` (string, nullable) to `clients`.
- `Client` model: all four added to `$fillable`; `date_of_birth` cast to `date`. Deliberately NOT added to the `encrypted` cast list (see Design notes).
- `StoreClientRequest` / `UpdateClientRequest`: all four `nullable`; `date_of_birth` also `date`, `before:today`; `province` validated with `Rule::in([...])` against Thailand's 77 provinces (a PHP constant/array, not a DB-editable list — see Design notes).
- `ClientResource`: exposes all four fields.
- Agent Portal `ClientsView.vue`: added to the create form (all optional) and shown read-only in the detail drawer's contact-info block.
- Admin `ClientManagementView.vue`: read-only display, matching this screen's existing pattern.
- Feature tests: round-trip save for all four fields; future `date_of_birth` rejected (422); invalid `province` rejected (422); existing client-creation tests still pass with all four omitted.

Acceptance Criteria:
  - A client can be created (or left) with all four fields blank — none of CLAUDE.md §2's core SWS Referral fields are affected
  - `date_of_birth` in the future is rejected (422)
  - `province` only accepts a real Thai province name (422 otherwise), sourced from Thailand's official 77-province list, not invented ad hoc
  - Tenant isolation unaffected — no new table, existing `TenantScope` on `clients` already covers these columns
  - Fields are visible in both frontends per the read/write split above
  - `eslint` / `vue-tsc --build` / `vite build` clean (both apps); `php artisan test` passes

Out of scope (future tasks):
  - Editing an already-created client's fields from the Agent Portal — there is currently no "edit client" screen/action anywhere (only create); building one is a separate, larger task that applies to every Client field, not just these four, and shouldn't be smuggled into this one
  - National ID / passport number — deliberately excluded per the earlier CRM-comparison report: this system doesn't perform underwriting, and if a signed-contract flow later needs identity verification, that belongs as a `ClientDocument` upload (already exists), not a new sensitive text field
  - Encrypting these four columns at rest (see Design notes)

Design notes (flag if wrong):
  - `province` uses a fixed, hardcoded 77-item list rather than a `provinces` config table — unlike commission %/pricing (BR-7), a list of Thai provinces isn't a business value that changes. If the human wants this to support future non-Thailand tenants, that's a genuinely different, bigger task (a real geography config table) — flag it.
  - `date_of_birth`/`address`/`occupation` are treated as ordinary personal data (Section 6), not the "sensitive" category `health_notes` already gets (`encrypted` cast). If the human wants these encrypted too, it's cheap to add later since these are brand-new columns with no existing data to migrate — flag if wanted now instead of later.
  - The existing `consent_given_at` timestamp is reused as-is for this expanded set of collected fields (no new consent flag added) — flag if the human wants a broader/separate consent record now that more personal-data categories are collected.
