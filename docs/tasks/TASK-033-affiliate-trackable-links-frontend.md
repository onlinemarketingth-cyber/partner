Task: Affiliate trackable links — frontend
Owner: ag-ui
Goal: Build the Agent Portal UI for generating/sharing trackable links and viewing click/conversion stats, plus the new public-facing landing/lead-capture page that TASK-032's `POST /api/v1/public/affiliate-leads/{token}` submits to.
Related: BR-7, ADR-011 Section 4, CLAUDE.md Section 7 (frontend structure), medical-saas CLAUDE.md UI/UX standards (Icon.vue, HeroHeader, no emoji, Kanit font — applies to any new Sync Vision Agent screens per this project's own Section 7 conventions where equivalent components exist)
Input:
  - TASK-032's completed API: link generation/list endpoints, click/conversion stats endpoint, public lead-capture endpoint.
Expected output:
  - Agent Portal (`/frontend`) screen: "My Affiliate Links" — generate a new link (optionally scoped to one product), copy-to-clipboard, view click count + conversion count + attribution-window setting (read-only, admin-set).
  - A new **public, unauthenticated** route/page (outside the SPA's normal authenticated shell) that the `GET /l/{token}` redirect lands on or that the lead-capture form posts from — this needs its own minimal layout since it's shown to prospects who have never logged in, not existing agents.
  - Client-side validation on the public lead-capture form mirrors (does not replace) the backend Form Request validation from TASK-032.
  - Loading/empty/error states for all new views, consistent with Definition of Done (Section 9).
Acceptance Criteria:
  - Works correctly across Desktop / Tablet / Mobile (Section 9) — the public lead-capture page especially, since prospects will most often click a shared link on mobile.
  - Core workflow (generate a link, copy it) completable in ≤ 3 clicks (Section 9).
  - No `v-html` on any user-supplied content (Section 6 — XSS).
  - Passes lint/format (ESLint/Prettier).
  - Public page makes no authenticated API calls and carries no Sanctum session assumption.
Out of scope: Any backend logic (TASK-032, already complete); admin-side attribution-window configuration screen (grouped into TASK-034 alongside the other new plan-type admin screens).
Depends on: TASK-032
Blocks: TASK-034
