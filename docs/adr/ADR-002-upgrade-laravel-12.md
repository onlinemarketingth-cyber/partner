# ADR-002: Upgrade Backend from Laravel 11 to Laravel 12

- **Date:** 2026-07-08
- **Status:** Accepted (approved by human)
- **Author:** ag-lead

## Context

CLAUDE.md Section 3 originally fixed the backend at Laravel 11
(PHP 8.3+). During initial scaffolding, `composer install` failed:

```
Root composer.json requires laravel/framework ^11.31, found
laravel/framework[v11.31.0, ..., v11.54.0] but these were not loaded,
because they are affected by security advisories (PKSA-m5cs-t1y6-qpcs,
PKSA-3r5d-mb8f-1qw9, PKSA-mdq4-51ck-6kdq, PKSA-8qx3-n5y5-vvnd,
PKSA-q46n-4fdk-zjr4, PKSA-qzrn-rnz3-85w1).
```

Investigation (Composer 2.9+ blocks installing any package version with
an open security advisory by default) traced this to a root cause, not
a config fluke: **Laravel 11 reached end-of-life on 2026-03-12** and no
longer receives security patches. Every currently published 11.x
release is therefore permanently flagged — there is no future patched
11.x version to bump to. Laravel 12 is current, with security fixes
through 2027-02-24.

## Options Considered

1. **Stay on Laravel 11, disable Composer's audit block**
   (`config.audit.block-insecure = false` or blanket `audit.ignore`).
   Rejected: installs a framework with open, permanently-unpatched
   security advisories on a project handling PDPA-sensitive client
   health data — conflicts with CLAUDE.md Section 6 (OWASP/ASVS
   baseline).
2. **Upgrade to Laravel 12.** Chosen. Actively supported, no code
   written yet (day-zero of the project — no migration cost), skeleton
   structure is unchanged (verified: `bootstrap/app.php`, routes, and
   config layout are effectively identical between 11.x and 12.x
   skeletons).
3. Pin to a specific advisory-ignored 11.x version with a documented
   reason. Rejected for the same reason as option 1 — the advisories
   don't have a patched target to pin to; this project has zero
   production risk from doing the upgrade now, so there is no reason to
   accept the risk.

## Decision

Upgrade the backend target to **Laravel 12 (PHP 8.3+)**. `composer.json`
updated to `laravel/framework: ^12.0`, `php: ^8.3`. CLAUDE.md Section 3
updated accordingly. All scaffolded structure (Section 7 folders,
Sanctum SPA wiring, `TenantScope` stub, `/api/v1` routing) carried over
unchanged — none of it was Laravel-11-specific.

## Consequences

- No other CLAUDE.md rule changes — BR-1..BR-7, Section 5 (tenant
  isolation), Section 6 (security), Section 7 (code standards) all
  apply identically under Laravel 12.
- Composer dependency versions in `composer.json` for `laravel/pail`,
  `laravel/pint`, `laravel/sail`, `nunomaduro/collision`,
  `phpunit/phpunit` were bumped to their Laravel-12-compatible floors
  (from the official `laravel/laravel` 12.x skeleton) — no manual
  guessing involved.
- Going forward, re-check framework EOL status before starting any new
  major initiative, since support windows are time-based, not tied to
  this document being reviewed.

## Related

- CLAUDE.md Section 3 (Architecture), Section 6 (Security Standards)
- ADR-001 (local dev environment)
