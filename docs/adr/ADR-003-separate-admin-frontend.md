# ADR-003: Split Admin into a Separate Vue App from the Agent Portal

- **Date:** 2026-07-08
- **Status:** Accepted (approved by human)
- **Author:** ag-lead

## Context

CLAUDE.md Section 7's Recommended Project Structure originally noted:

```
/frontend  (Vue 3 SPA — Agent Portal + Admin in one monorepo, split by route)
```

This was a structural note, not one of the "Architecture (Decided — Do
Not Change Without Approval)" items in Section 3 (those are Laravel 12
API, Vue 3 SPA, MySQL 8, Sanctum). The human asked to separate the
Agent Portal UI from the Admin UI and confirmed the intended scope:
not just a different layout/nav within the same app, but two
independent Vue apps with their own build.

## Options Considered

1. **Layout-level split, same app/build.** A distinct `AdminLayout.vue`
   for `/admin/*` routes, sharing one Vue project, one build, one
   login. Lowest effort, zero deployment/auth changes, matches Section
   7's original text exactly. Rejected — human explicitly asked for
   separate apps/builds, not just a different chrome inside one app.
2. **Two independent Vue apps, one shared Laravel API.** `/frontend`
   (Agent Portal) stays as-is; a new `/frontend-admin` project is
   added with its own `package.json`, build, dev server, router, and
   login screen. Both call the same backend (`/api/v1/...`) over
   Sanctum SPA session auth. **Chosen.**
3. **Two apps + a shared component package** (e.g. a local workspace
   package for `design-system/`). Would avoid duplicating
   `Icon.vue`/`AppLogo.vue`/etc. between the two projects. Deferred,
   not rejected — needs a package-manager workspace setup (pnpm/npm
   workspaces) that doesn't exist yet. Noted as a follow-up once the
   admin app's real component needs are known; duplicating a handful
   of small presentational components now is cheap, premature
   abstraction is not.

## Decision

Build `/frontend-admin` as a second, independent Vue 3 + Vite + TS +
Tailwind + Pinia + Vue Router project, sibling to `/frontend`. Both
authenticate against the same Laravel backend via Sanctum SPA session
cookies — since the cookie is scoped to the backend's domain, not the
calling frontend's origin, a company_admin/super_admin who's logged
into either app is already authenticated against the other (same
backend, no separate credential store). Each app gets its own CORS +
`SANCTUM_STATEFUL_DOMAINS` entry on the backend.

`design-system/` components needed by the admin app (`Icon.vue`,
`AppLogo.vue`, `HeroHeader.vue`, `EmptyState.vue`) are duplicated into
`/frontend-admin/src/design-system/` rather than shared via a package,
per Option 3's rationale above. Both copies must stay in sync with
CI-002 (brand/gold) manually until/unless a shared package is built —
flagged here so a future color or shape-language change is applied to
both.

The Agent Portal's `TopNavigation.vue` drops its `admin` nav item;
company_admin/super_admin users instead get a link out to the admin
app's URL.

## Consequences

- **Two dev servers, two ports** during local dev (agent frontend on
  5173, admin app on a new port — see SETUP.md). Two `npm install` /
  `npm run build` / `npm run dev` invocations going forward.
- **Two logins.** Not a real extra step for the user (same credentials,
  same session-sharing mechanism described above) but two separate
  `LoginView`-style screens to maintain in code.
- **Duplication risk.** The design-system components now exist in two
  places. Section 7's "no magic numbers/strings" and DRY principles are
  in tension with "fully separate" here — accepted as a deliberate
  trade-off per the human's explicit choice, with the Option 3 shared-
  package path left open if duplication becomes painful.
- **Deployment** now needs two separate static builds/hosts (or two
  paths behind a reverse proxy) instead of one — not yet decided, out
  of scope for this ADR; flag for a future ADR once production hosting
  is discussed.
- CLAUDE.md Section 7 updated to reflect two frontends instead of one
  monorepo. Section 10 (Agent Team & Workflow) unaffected — ag-ui still
  owns "Agent Portal + Admin" screens, just across two projects now.

## Related

- CLAUDE.md Section 3 (Architecture), Section 5 (Multi-Tenancy —
  company_admin/super_admin visibility rules, unchanged by this split),
  Section 7 (Project Structure, updated)
- docs/design/CI-002-genesenn-brand.md (palette both apps must share)
