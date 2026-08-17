# Deployment — Sync Vision Agent

TASK-205 / ADR-034. One command from the repo root — `npm run deploy` —
builds both Vue apps, pushes to GitHub, and deploys all three apps
(backend, frontend, frontend-admin) to Hostinger over SSH.

This only works after the **one-time setup** below has been done once,
by hand, on the actual Hostinger account. Nothing in this repo can do
that part for you — it needs your real GitHub/Hostinger credentials,
which no agent should ever hold.

---

## One-time setup (do this once, by hand)

### 1. GitHub

Repo: `https://github.com/onlinemarketingth-cyber/partner`

```bash
cd "/path/to/agent"
git remote add origin https://github.com/onlinemarketingth-cyber/partner.git
# or, to avoid typing a token on every push, switch the remote to SSH
# once you've added a deploy key to your GitHub account:
#   git remote set-url origin git@github.com:onlinemarketingth-cyber/partner.git
git push -u origin main
```

If you push over HTTPS, GitHub will ask for a Personal Access Token
(not your password) the first time — cache it with
`git config --global credential.helper store` (or your OS's credential
manager) so `npm run deploy` doesn't stop to ask for it every time.

### 2. Hostinger SSH access

hPanel → **Advanced → SSH Access** → note the host, port, and username.
Hostinger shared hosting almost never uses port 22.

Generate a local key (if you don't have one) and authorize it so
deploys never prompt for a password:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/hostinger_deploy
ssh-copy-id -i ~/.ssh/hostinger_deploy.pub -p <SSH_PORT> <SSH_USER>@<SSH_HOST>
```

Test it: `ssh -p <SSH_PORT> -i ~/.ssh/hostinger_deploy <SSH_USER>@<SSH_HOST>`
should log in with no password prompt.

### 3. Domains / subdomains

Create 3 subdomains in hPanel (Domains → Subdomains), one per app, e.g.:

| App | Suggested subdomain | Document root |
|---|---|---|
| Backend (Laravel API) | `api.yourdomain.com` | points at `backend/public` — see step 4 |
| Agent Portal (frontend) | `agent.yourdomain.com` | its own `public_html`-style folder |
| Admin (frontend-admin) | `admin.yourdomain.com` | its own `public_html`-style folder |

Hostinger's hPanel lets you set a subdomain's document root to any
folder path — it does not have to be `public_html`. Use whatever path
you set here as `FRONTEND_REMOTE_PATH` / `FRONTEND_ADMIN_REMOTE_PATH`
in `.env.deploy` later.

### 4. Clone the backend on the server (once)

The deploy script only ever `git pull`s the backend on the server — it
never clones it for you, and it never writes the server's `.env`. Do
this once, by hand, over SSH:

```bash
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>
cd ~/domains/api.yourdomain.com   # or wherever you want it
git clone https://github.com/onlinemarketingth-cyber/partner.git backend
cd backend/backend                # the Laravel app lives in the repo's backend/ subfolder
cp .env.example .env
nano .env                         # fill in real DB creds, APP_URL, mail, etc. — see CLAUDE.md §5/§6
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan storage:link
```

Then point the `api.yourdomain.com` subdomain's document root at
`~/domains/api.yourdomain.com/backend/backend/public`.

**BACKEND_REMOTE_PATH in `.env.deploy` must be the `backend/backend`
folder above** (the one containing `artisan`), not the outer
`backend/` clone folder and not `public/`.

Also confirm PHP 8.3+ and Composer are actually reachable over SSH —
some shared plans alias the default `php`/`composer` to an old
version:

```bash
php -v
composer -V
# if not 8.3+, find the versioned binary Hostinger exposes, e.g.:
which php8.3
```

If you need a versioned binary, set `PHP_BIN=php8.3` /
`COMPOSER_BIN=composer8.3` (or whatever your plan calls it) in
`.env.deploy`.

Also confirm `ffmpeg` and `poppler-utils` (`pdftoppm`/`pdfinfo`) are
installed if you rely on video compression or PDF spec-sheet
thumbnails — see SETUP.md's ADR-007/ADR-008 notes. Shared hosting may
not have these; the app degrades gracefully without them (uploads
still work, just skip processing).

Set up a queue worker (`php artisan queue:work`) via Hostinger's cron
job scheduler if your plan doesn't support a persistent process — see
SETUP.md for which notifications/jobs actually depend on it.

### 5. Local deploy config

```bash
cp .env.deploy.example .env.deploy
```

Fill in the real `SSH_HOST` / `SSH_PORT` / `SSH_USER` /
`BACKEND_REMOTE_PATH` / `FRONTEND_REMOTE_PATH` /
`FRONTEND_ADMIN_REMOTE_PATH` from steps 2-4 above. `.env.deploy` is
gitignored — it stays local to your machine only.

### 6. Production API URLs for the two Vue apps

`frontend/.env` and `frontend-admin/.env` are **committed dev
defaults** (`http://agent.localhost:8010` / `http://admin.localhost:8010`
— see those files' own comments for why). A production build must
override them, or the live site will silently call `localhost` and
every request will fail. Create the real overrides once:

```bash
cp frontend/.env.production.example frontend/.env.production
cp frontend-admin/.env.production.example frontend-admin/.env.production
```

Fill in your real `https://api.yourdomain.com`-style URL from step 3 in
both. Both files are gitignored (deploy-target-specific, not secret,
but not something a fresh clone should inherit blindly). `deploy.sh`
refuses to build if either is missing.

---

## Regular workflow

```bash
git add -A
git commit -m "feat: whatever you built"
npm run deploy
```

`npm run deploy` will:

1. Refuse to run if there are uncommitted changes.
2. Build `frontend/` and `frontend-admin/` (`npm run build`, which
   type-checks first — fails loudly on a real error, doesn't ship a
   broken build).
3. Push your current branch to GitHub.
4. Upload each app's `dist/` to its Hostinger subdomain folder via
   rsync.
5. SSH into Hostinger and update the backend: put the site in
   maintenance mode, `git fetch` + hard-reset to the pushed commit,
   `composer install --no-dev`, `migrate --force`, cache
   config/routes/views, restart the queue worker, take the site out of
   maintenance mode.

It asks for confirmation before touching anything remote (skip with
`-y`/`--yes`). Useful flags:

```bash
npm run deploy:dry-run          # print every command, run nothing
npm run deploy:frontend-only    # skip the backend SSH step entirely
bash scripts/deploy.sh --skip-frontend --skip-frontend-admin  # backend only
bash scripts/deploy.sh --skip-push      # deploy without pushing to GitHub
```

If the backend deploy fails partway through, the script will tell you
the site may still be in maintenance mode — SSH in, check the error
(`git log`, `composer install` output), fix it, and run
`php artisan up` yourself once resolved.

---

## What this deliberately does NOT do

- **No zero-downtime release-folder swap.** Shared hosting isn't set
  up for it; the script uses a short `artisan down`/`up` maintenance
  window instead (matches the realistic constraints of a shared-hosting
  SSH deploy, not an enterprise blue-green setup).
- **No database backup before `migrate --force`.** Set up your own
  scheduled backup on Hostinger (hPanel has a built-in backup feature)
  or `spatie/laravel-backup` if you want one — not built here, this
  script trusts an existing backup policy rather than inventing one.
- **No secrets are ever read or written by this script or stored in
  this repo.** `backend/.env` on the server is edited once by hand
  (step 4) and never touched again by `deploy.sh`.
