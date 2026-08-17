# ADR-001: Local Development Environment Setup

- **Date:** 2026-07-08
- **Status:** Accepted
- **Author:** ag-lead

## Context

Project kickoff for Sync Vision Agent. The developer machine already has
MAMP (Apache + MySQL + PHP) and phpMyAdmin installed. Tech stack itself
(Laravel 11 API + Vue 3 SPA + MySQL 8 + Sanctum) was already decided in
CLAUDE.md Section 3 — this ADR only covers how the two apps run locally
during development, since API-only Laravel does not need MAMP's Apache.

## Options Considered

**1. How the Laravel API is served locally**
- (a) `php artisan serve` on a dedicated port (e.g. 8000)
- (b) MAMP Apache vhost pointed at `backend/public`

**2. MySQL connection**
- (a) MAMP's default MySQL instance (127.0.0.1:8889, root/root)
- (b) A separate/custom MySQL instance

## Decision

- Serve the API with **`php artisan serve --port=8000`**. Reason: this is
  an API-only Laravel app (Blade forbidden, Section 3) with no need for
  Apache vhost routing; `artisan serve` is simpler for day-to-day dev and
  matches how `laravel/sail`-style teams typically iterate. MAMP's Apache
  remains free for phpMyAdmin and any future static needs.
- Use **MAMP's default MySQL** (127.0.0.1:8889, root/root) for the `mysql`
  connection in `backend/.env`, database `sync_vision_agent`. Simplest
  path since MAMP is already installed and running.
- Vue 3 SPA runs via **Vite dev server** (`npm run dev`, default port
  5173), calling the API at `http://localhost:8000/api/v1`. Sanctum SPA
  auth (stateful, cookie-based) requires `SANCTUM_STATEFUL_DOMAINS` to
  include `localhost:5173`.
- Monorepo layout: `/backend` (Laravel API) and `/frontend` (Vue 3 SPA)
  as siblings at the project root, per CLAUDE.md Section 7's recommended
  structure.

## Consequences

- Two dev servers must run concurrently (`php artisan serve` +
  `npm run dev`); documented in `/SETUP.md`.
- CORS + Sanctum stateful-domain config must be kept in sync if the Vite
  port or API port ever changes.
- Because `artisan serve` is used, MAMP's Apache/vhost config is *not*
  part of this project's request path — MAMP is only providing MySQL +
  phpMyAdmin here.
- If the team later needs Apache-fronted URLs (e.g. matching a shared
  `.test` domain convention), revisit this ADR — switching to a MAMP
  vhost is a config-only change, not an architecture change.

## Related

- CLAUDE.md Section 3 (Architecture), Section 7 (Project Structure)
