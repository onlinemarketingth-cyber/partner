Task: Affiliate trackable links — backend
Owner: ag-dev
Goal: Build full-mode Affiliate support — trackable links, click logging, an attribution window, and a public lead-capture endpoint. This is the first unauthenticated/public write surface in the entire codebase (a genuine departure from CLAUDE.md Section 3's "everything behind Sanctum auth" assumption) and must be treated with the same rigor as any auth'd endpoint, plus bot/spam mitigation since it has no login.
Related: BR-2, BR-4, BR-7, ADR-010 (Option 4, research), ADR-011 Section 4, CLAUDE.md Section 3 (architectural departure — flagged), Section 6 (rate limiting, PDPA, validation)
Input:
  - `CommissionPlanType::Affiliate` enum case (added in TASK-027).
  - Existing `Client` and `Referral` models — a lead-capture submission creates one of each, same as a manually-submitted SWS Referral does today.
Expected output:
  - Migration: `affiliate_links` (id, company_id, agent_id, product_id nullable, token (unique, unguessable — use a cryptographically random string, not an incrementing ID, to prevent enumeration), created_at).
  - Migration: `affiliate_link_clicks` (link_id, clicked_at, ip_hash (hashed, not raw IP — PDPA), user_agent).
  - Migration: `affiliate_attribution_settings` (company_id, attribution_window_days, new_vs_returning_rate_differential_enabled) — BR-7, every value admin-editable, no default asserted here.
  - Migration: add nullable `referrals.affiliate_link_id`.
  - New public routes (outside the `auth:sanctum` middleware group, but still rate-limited per Section 6):
    - `GET /l/{token}` — logs the click, redirects to the product/landing page.
    - `POST /api/v1/public/affiliate-leads/{token}` — validated via a Form Request (never trust the client, even unauthenticated), creates `Client` + `Referral` with `affiliate_link_id` set, applies the attribution-window rule (a click outside the configured window does not attribute).
  - Bot/spam mitigation on the public POST endpoint — a specific mechanism (honeypot field, CAPTCHA-equivalent, or similar) must be chosen and documented; flag back to ag-lead/human if a specific choice needs approval before building rather than silently picking one.
  - Rate limiting explicitly applied to both public routes (Section 6 — "Rate Limiting / Throttling: applied to every public endpoint").
  - Admin/Agent API: CRUD for generating an agent's own trackable link, list clicks/conversions.
Acceptance Criteria:
  - `affiliate_links.token` is unguessable (not sequential/incrementing) — verified by test that IDs cannot be enumerated to discover another agent's link.
  - A click outside the configured `attribution_window_days` does not create attribution on a subsequent lead-capture submission.
  - The public POST endpoint validates all input via a Form Request exactly as any authenticated endpoint would (Section 6).
  - Rate limiting is active on both public routes.
  - `ip_hash` stores a hash, never a raw IP (PDPA, Section 6).
  - Money/commission from an affiliate-attributed sale still flows through the standard immutable `commission_ledger` (BR-4) — no parallel/duplicate ledger mechanism.
  - Tenant isolation must pass for all authenticated endpoints in this task (cross-tenant access → 403/404); the 2 public endpoints are intentionally unauthenticated by design (documented, not a bug) but must not leak cross-tenant data (e.g. `{token}` alone must be sufficient — no way to pass a different company_id and have it apply cross-tenant).
  - Tests cover: valid click+conversion within window, expired-window non-attribution, bot-mitigation rejection, rate-limit triggering, and enumeration-resistance of tokens.
Out of scope: Frontend link-generation UI and public landing/lead-capture page (ag-ui, TASK-033); choosing the exact bot-mitigation mechanism without human sign-off if it has cost/UX trade-offs worth flagging.
Depends on: TASK-027
Blocks: TASK-033, TASK-034
