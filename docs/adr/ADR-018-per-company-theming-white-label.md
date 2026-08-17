# ADR-018: Per-Company Theming / White-label (colors, background, Google Fonts, logos, loading screen, label overrides)

- **Date:** 2026-07-24
- **Status:** Accepted — **BUILT** 2026-07-24 (Phases 1–4). Human-confirmed 4 decisions. P1 backend (schema/endpoints/seed/tests — 7 tests green), P2 Agent-Portal core (Tailwind→CSS-var refactor, boot theme loader, loading splash, label helper), P3 frontend-admin theme settings screen + live preview, P4 polish (2nd demo tenant `genesenn` with a distinct brand, Config-Health `theme_configured` flag). Migrations + tests run by the human (sandbox has no PHP). Deferred: `/c/{slug}` path routing + subdomain resolution, `frontend-admin` self-theming, full i18n label coverage, self-hosted fonts.
- **Author:** ag-lead
- **Related:** CLAUDE.md §3 (Vue SPA + Tailwind), §5/BR-6 (multi-tenant), §6 (private files), BR-7 (admin config, never hardcoded). ADR-003 (two frontends). Personal user background (TASK-068+, separate & unchanged). TASK-055.

## Context

The human wants each **company (tenant)** to white-label the **Agent Portal (`frontend`)**: custom button/menu **labels**, **colors**, **background**, **font (Google Fonts)**, **logos** (nav, login, favicon, loading), and a branded **loading screen** — all per company, all admin-editable (BR-7). Today: brand colors are **hard-coded hex in `tailwind.config.js`** (compiled, not runtime-changeable), `companies` has **no branding columns**, and the only theming that exists is a **personal** per-user background (a different, unrelated system that stays as-is).

## Decisions (human-confirmed 2026-07-24)

1. **Branding applies from the login screen onward, with a loading splash shown before login.** Colors/fonts are delivered as **CSS variables** loaded at boot, so they can change at runtime per company. **Pre-login company resolution (ag-lead technical call):** resolve by **URL slug `/c/{slug}`** → else the **last-used company remembered in `localStorage`** → else a **neutral default** brand; the loading splash renders the default first and swaps to the company theme the instant the public theme endpoint resolves. (Subdomain-based resolution is deliberately deferred — no DNS/host work this phase — and can be added later without reshaping the data model.)
2. **Company Admin configures their own company's theme** (+ Super Admin any). This requires a **new self-service company-theme endpoint** scoped to `auth->company_id` (the existing company-write endpoint is Super-Admin-only — same gap flagged in TASK-054; this ADR closes it for theme fields only, not general company editing).
3. **Label overrides = a curated important set** (app name, main nav/menu items, key CTA buttons) via a `label_overrides` JSON map + a small `t(key, default)` helper — NOT a full app-wide i18n pass.
4. **Agent Portal (`frontend`) only.** `frontend-admin` keeps the default brand this phase (its Tailwind config is untouched).

## Core technical decision — colors must become CSS variables

Tailwind compiles classes ahead of time, so runtime per-company colors are impossible with today's hard-coded hex palette. The `brand` (and where needed `gold`) palette in `frontend/tailwind.config.js` will be **refactored to reference CSS variables** (e.g. `brand: { 500: 'var(--brand-500)', … }`), with the current GENESENN hex values moved into `:root` as the **defaults**. At boot, the theme loader overrides those `:root` vars per company. This touches every `bg-brand-*/text-brand-*` usage app-wide → **regression testing of the whole Agent Portal is a required step**, not optional.

## Data model

New table **`company_theme_settings`** (one row per company, TenantScope, BR-7 admin config — no value hardcoded in logic; all live here or fall back to code defaults):
- `company_id` (FK, unique)
- Colors: `primary_hex` (+ the loader derives the 50–900 ramp, OR store `brand_ramp` JSON if the admin wants full control — start with a single primary + generated ramp), `accent_hex` (nullable, maps to `gold`)
- Background: `background_type` (`solid` | `gradient` | `image`), `background_config` (JSON: colors/angle), `background_image_path` (nullable)
- Font: `font_family` (Google Font name, e.g. "Kanit"), `font_weights` (JSON, e.g. [400,500,700])
- Logos (all nullable, private-or-public disk paths): `logo_nav_path`, `logo_login_path`, `favicon_path`, `logo_loading_path`
- Loading screen: `loading_bg_hex` (nullable), `loading_message` (nullable)
- Labels: `label_overrides` (JSON map, curated keys → text)
- timestamps

Logos/backgrounds are **image files**: stored on the **public** disk (they are meant to be shown to anyone hitting the branded login — same reasoning as announcement media, not private like slips), served by direct URL. Uploads validated (image mime, size cap) like existing avatar/announcement uploads.

## Delivery mechanism (frontend)

- **Public read endpoint** `GET /public/theme/{slug}` (unauthenticated, throttled) → returns the company's theme (colors, font, logo URLs, background, loading config, label_overrides) for pre-login branding. Also `GET /me/theme` (or fold into `/me`) for the authenticated company's theme.
- **Boot loader** (`frontend/src/theme/…`): before mounting, resolve the company (slug/localStorage/default), fetch the public theme, then: set `:root` CSS vars (colors, `--app-font`, background), inject the Google Fonts `<link>` for `font_family`+weights, set `<link rel=icon>` favicon, and expose logo URLs + `label_overrides` via a small Pinia `theme` store.
- **Loading splash**: a minimal branded screen (logo + loading color) shown while the SPA boots and the theme resolves — replaces the current blank flash.
- **Labels**: `t(key, default)` reads `label_overrides[key] ?? default`; wire the curated keys into the app shell (nav labels, app name, key CTAs).
- **Logos**: `AppLogo`, the login page, the top bar, and the loading splash read their image from the theme store (fall back to the built-in `AppLogo` mark when unset).

## Security / BR notes
- Tenant isolation (§5/BR-6): theme rows are TenantScope'd; the self-service write endpoint forces `company_id = auth->company_id`; the public read endpoint exposes ONLY presentational fields by slug (no PDPA, no counts, no ids beyond the theme).
- BR-7: every brand value is config/seed; code holds only the neutral **default** fallback.
- Google Fonts is an external resource loaded at runtime — acceptable for branding; self-hosting fonts is a possible future hardening (noted, not done).

## Phased plan
1. **Backend** — migration (`company_theme_settings`) + model + `ThemeService` + `ThemeResource` + self-service write endpoint (Company Admin) + public read-by-slug endpoint + logo/background upload endpoints + seed Thai Life's theme. Feature tests (tenant isolation, self-scope, public read shape, upload).
2. **Frontend core (Agent Portal)** — refactor `tailwind.config.js` brand/gold to CSS vars + `:root` defaults; boot theme loader (resolve → fetch → apply vars/font/favicon/logos); Pinia `theme` store; branded loading splash; `t()` label helper; wire logos + curated labels into the shell/login. **Full-app regression pass.**
3. **Admin UI (in Agent Portal's own settings, Company-Admin-visible)** — "ตั้งค่าธีม/แบรนด์" screen: primary/accent color pickers, background picker, Google-Font picker, logo uploads (nav/login/favicon/loading), loading-screen config, curated label editor, and a live preview. (Company Admin also uses the Agent Portal, so the settings screen lives there gated to Company Admin — or in frontend-admin if preferred; TODO confirm placement at build time.)
4. **Polish** — seed/demo, docs, and a config-health note (which companies have themed vs default).

## Consequences
- Reusable, safe white-label per tenant with no infra changes now; subdomain + `frontend-admin` theming + full i18n remain clean future extensions.
- The Tailwind→CSS-var refactor is the main risk surface (app-wide visual regression) and gates Phase 2 acceptance.
- `// TODO: CONFIRM` — exact placement of the theme-settings screen (Agent Portal settings vs frontend-admin) decided at Phase 3 start.
