# ADR-034 — One-command deploy pipeline (GitHub + Hostinger)

- **Status:** accepted
- **Date:** 2026-08-17
- **Human decisions feeding this (2026-08-17):**
  1. Hostinger target is **shared hosting with SSH access** (confirmed by KreangYot — not VPS,
     not SSH-less shared hosting).
  2. GitHub repo already exists: `https://github.com/onlinemarketingth-cyber/partner`.
  3. One command must cover **all 3 apps** — backend (Laravel API) + frontend (Agent Portal) +
     frontend-admin (Admin) — not just the two Vue frontends.
- **Related:** CLAUDE.md §3 (architecture), §7 (recommended project structure), §9 (Definition of
  Done). No BR-x rule governs deployment — this is infrastructure, not business logic.

---

## 1. Context

The repo had **no `.git` at all** at the project root before this task — confirmed via
`git status` (`fatal: not a git repository`) across `backend/`, `frontend/`, `frontend-admin/`.
`SETUP.md`'s original scaffold notes (line ~261) mention `git init && git add -A && git commit`
as an intended first step that evidently never happened. There was also no root `package.json`,
no deploy script, and no CI/deploy config anywhere in the tree.

KreangYot asked for a single `npm run deploy` from a terminal to push to GitHub and deploy to
Hostinger. This is genuinely an architecture decision (CLAUDE.md §3) with several values that
cannot be guessed on the human's behalf (same spirit as BR-7, even though this isn't a business
rule): Hostinger's hosting tier/access method, whether a GitHub repo already existed, and how
much of the stack one command should cover. All three were asked via `AskUserQuestion` before any
file was written — see chat history 2026-08-17.

## 2. Options considered for the backend deploy step

**A. rsync the whole `backend/` tree to the server on every deploy.**
Simple, but re-uploads `vendor/` (large, slow) unless carefully excluded, and any file the
server-side process has changed since (log files, cache) risks being clobbered or fighting the
rsync `--delete` flag. No natural way to run `composer install`/`migrate` without a follow-up SSH
call anyway, so rsync buys little over just running git commands over SSH.

**B. `git pull`/`git reset --hard` on an existing server-side clone, over SSH — chosen.**
The server keeps its own git clone of the same repo (one-time `git clone`, done by hand — see
`docs/DEPLOYMENT.md` step 4). Every deploy just fetches and hard-resets to the pushed commit, then
runs `composer install --no-dev`, `migrate --force`, and Laravel's cache commands. `vendor/`,
`storage/`, and `.env` are all gitignored, so a hard reset never touches them. This is the
standard pattern for deploying a git-tracked PHP app to a host with SSH but no CI runner, and it
means the exact commit that's live on the server is always directly inspectable (`git log`) from
an SSH session — useful for a solo/small team debugging a shared-hosting box.

**C. A CI-based pipeline (GitHub Actions building + deploying on every push).**
Rejected for now: adds a layer (Actions secrets, a runner, SSH key management inside GitHub) for a
single-developer/small-team shared-hosting target where "run one command from my own terminal" was
the explicit ask. Nothing here blocks moving to Actions later if the team grows — the same
`.env.deploy` variables would become GitHub Secrets, and `scripts/deploy.sh`'s logic is already
factored to be callable from a CI job instead of a local shell with only the SSH-connectivity
check needing to change (CI runners don't share a human's SSH agent the way this script currently
assumes).

## 3. Options considered for the two Vue frontends

**A. Build on the server.** Rejected outright — Hostinger shared hosting has no Node runtime by
default, and even where Node is available on a shared plan it's typically not meant for a full
Vite build (memory limits). Confirmed no Node-related SETUP.md deployment note exists.

**B. Build locally, rsync only `dist/` — chosen.** Both `frontend/` and `frontend-admin/`
`package.json` `"build"` scripts already run `vue-tsc --build` before `vite build` (`run-p
type-check "build-only"`), so a broken build fails loudly on the developer's own machine before
anything ships — no server-side build step to keep in sync with two separate app configs.

## 4. Safety choices baked into `scripts/deploy.sh`

- **Refuses to run with uncommitted changes.** Every deploy ships an exact, inspectable commit —
  never "whatever happens to be on disk right now." Matches BR-4's immutable-ledger spirit applied
  to deploys: what's live should always trace back to one specific commit.
  - Confirms SSH connectivity *before* building anything, so a bad `.env.deploy` fails in
    2 seconds, not after a multi-minute `npm ci && npm run build` on both apps.
  - Wraps the backend update in `php artisan down`/`up` (maintenance mode) so no request is ever
    served against a half-migrated schema.
  - Never re-clones the backend and never touches the server's `backend/.env` — those stay a
    one-time, by-hand setup step (`docs/DEPLOYMENT.md` §4), the same way this project's local dev
    setup already treats `.env` as something a human configures once, not something tooling
    generates.
- **No credentials are ever read, typed, or stored by this repo or this agent.** `.env.deploy`
  (SSH host/port/user/paths — not secrets in the password sense, but still environment-specific
  and not for git) is gitignored; actual authentication is delegated entirely to the developer's
  own SSH agent/key and git credential helper, set up once by hand per `docs/DEPLOYMENT.md` §1–2.

## 5. Consequences

- First real deploy requires the one-time manual setup in `docs/DEPLOYMENT.md` (SSH key
  authorization, subdomain document roots, one manual `git clone` + `.env` + `composer install` on
  the server). No agent can complete this part — it needs the human's actual GitHub/Hostinger
  access.
- If the team later moves off shared hosting (VPS, or a second environment/staging target),
  `scripts/deploy.sh` and `.env.deploy.example` need a second profile (e.g. `.env.deploy.staging`)
  — not designed for multi-environment yet, single production target only, matching what was
  asked for.
- `git reset --hard` on the server means any hand-edited file inside the server's backend clone
  (outside `.env`/`storage`/`vendor`) will be silently discarded on the next deploy — this is
  intentional (the server should never be a second source of truth for code), but worth stating
  explicitly since it's a real behavior change from "the server just sits there until someone SSHes
  in."
