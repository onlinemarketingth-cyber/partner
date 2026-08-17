# ADR-019: Public Product Share Links + Client Segmentation ("ส่วน Product" / "ส่วนลูกค้า")

- **Date:** 2026-07-29
- **Status:** Accepted — human-confirmed 2026-07-27 via AskUserQuestion (3 decisions below). Migrations + tests run by the human (sandbox has no PHP).
- **Author:** ag-lead
- **Related:** CLAUDE.md §5 (multi-tenant isolation), §6 (public routes throttled, no raw storage paths), BR-1 (Basic cert gate), BR-7 (admin-editable config). ADR-007 (product media/sales materials), ADR-011 (AffiliateLink). TASK-056.

## Context

The human asked for two new major sections inside the **Agent Portal** (`frontend/`, not `frontend-admin/` — Agents are blocked from the Admin app per task #161):

1. **Product section** — browse the catalog with search; mint a public, agent-attributed shareable link per product; the public page shows product media/details/sales documents; share via LINE/Email as a Link or QR code.
2. **Client section** — an order/payment link already exists (ADR-017, `/pay/{token}`); this task adds search + client segmentation ("ประเภทลูกค้า") and reuses the ADR-017 pay-link sharing UI for LINE/Email/QR.

## Decisions (human-confirmed 2026-07-27)

1. **Product-share public page is a standalone system, NOT an extension of `AffiliateLink` or `SalesMaterialShareLink`.** Three systems now coexist, each with a distinct purpose:
   - `AffiliateLink` (ADR-011) — lead capture. A prospect fills a form; conversion is tracked.
   - `SalesMaterialShareLink` (ADR-007) — a single file, expiring, revocable.
   - `ProductShareLink` (new) — a full public product showcase (all media + all sales materials), view-only, no lead-capture form, tied to one agent for attribution, no forced expiry (soft-revoke only).
   Reusing either existing system would have conflated unrelated semantics (lead capture vs. file sharing vs. product showcase); a new, narrow model was cheaper than overloading an old one's meaning.
2. **The public product page shows ALL of a product's media and sales materials automatically** — the agent does not hand-pick files per share. Simpler mental model ("share this product"), matches how `SalesMaterialShareLink` already works per-file if an agent wants a narrower share instead.
3. **Client category defaults = a generic starter set**, always admin-editable afterward (BR-7): `ลูกค้าใหม่ / ลูกค้าประจำ / VIP / มีความเสี่ยงเลิกซื้อ`. Seeded lazily per company on first visit to the category list (`ClientCategoryService::ensureDefaults()`), not a migration-time seed — covers companies created after this feature ships without a separate hook into company creation. Never re-seeds once a company has had any category row, so an admin who deletes all categories gets zero, not a silent respawn.

## Data model

- **`product_share_links`**: `company_id`, `agent_id` → users, `product_id` → products (NOT nullable, cascadeOnDelete), `token` (64-char opaque, unique), `view_count` (increments on each public view), `revoked_at` (nullable — soft-revoke, preserves view history). Unique-in-practice per (agent, product): `ProductShareLinkService::create()` reuses an existing unrevoked link instead of minting a duplicate.
- **`client_categories`**: `company_id`, `name`, `sort_order`. `clients.client_category_id` (nullable FK, `nullOnDelete`) — a client is never forced into a category.

## BR-1 gate

Minting a product-share link requires the agent to have passed **Basic** certification — same pattern as `AffiliateLinkService` and `SWS Referral` (`ValidationException` on the `agent_id` field, translated to a Thai message client-side). Browsing/searching the catalog stays open; only the "แชร์" action is blocked pre-certification.

## Security / privacy (§5, §6)

- Public routes (`GET /public/product-shares/{token}` + 3 file-stream routes) are unauthenticated, throttled (60/min read, 30/min material stream), and resolved by opaque token via `ProductShareLink::withoutGlobalScopes()->where('token', ...)` — `TenantScope` is a documented no-op for guests, so this is safe without a user context.
- `PublicProductShareResource` exposes only presentational fields (product name/description/price/specs/media/materials, agent + company display name) — never `company_id`, `agent_id`, the token itself, or `view_count`.
- Every media/material file is served through a controller route with an explicit ownership check (`abort_unless($media->product_id === $link->product_id, 404)`) — never a raw storage path, matching ADR-007's existing private-disk contract.
- `ProductResource::thumbnail_url` (added for the new Agent-Portal browse grid) is likewise a controller-served route, never a raw path, and only appears when `media` is eager-loaded (backward-compatible — no existing caller's response shape changes).

## Frontend

- New reusable `ShareLinkModal.vue` (Copy Link / QR Code / LINE / Email, plus the Web Share API when available) replaces the old plain "copy link" button on `OrdersView.vue`'s pay-link and is used for the new product-share link — one component, two callers, no duplicated share logic.
- `qrCode.ts` util generalizes the QR generation already used for PromptPay payment payloads (ADR-017) into `generateQrDataUrl(text, size)` for arbitrary URLs.
- New routes: `/products` (authenticated browse grid, Agent Portal) and `/p/:token` (public, unauthenticated, full-bleed — same chrome-less treatment as `/pay/:token` and `/l/:token`).
- Nav: added to `TopNavigation.vue`'s desktop nav row and to `HomeView.vue`'s mobile quick-actions (same precedent as the Orders card) — `BottomNav.vue`'s 5 fixed tabs were deliberately left unchanged, consistent with how `/affiliate-links` and `/orders` were surfaced.

## Consequences

- Three "shareable link" systems now exist in the codebase (`AffiliateLink`, `SalesMaterialShareLink`, `ProductShareLink`) with genuinely different semantics — a future contributor must not assume they're interchangeable; each has its own Policy/Service/Controller stack.
- `product_categories` (existing) vs. `client_categories` (new) are unrelated tables despite the similar name — one categorizes products, the other segments clients. No shared code between them.
- BR-7 flag: the 4 default client-category names are a starter seed, not a fixed taxonomy — Company Admins are expected to rename/add/delete via `GET/POST/PUT/DELETE /client-categories`.
- Follow-up (not built): per-share analytics beyond a raw `view_count` (e.g. which media were viewed), and bulk client category assignment — out of scope for this task, flagged here rather than guessed into existence.
