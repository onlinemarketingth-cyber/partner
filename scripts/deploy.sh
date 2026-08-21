#!/usr/bin/env bash
#
# scripts/deploy.sh — Sync Vision Agent one-command deploy.
#
# Run via `npm run deploy` from the repo root. What it does, in order:
#   1. Git pre-flight, so one command really is one command: offers to
#      commit a dirty tree (interactively — never under --yes), and, if
#      you are on a feature branch, pushes it and merges it into
#      GIT_BRANCH, which is the branch the server actually resets to.
#   2. Builds frontend/ and frontend-admin/ locally (vite build, which
#      also type-checks — see each app's package.json "build" script).
#      Hostinger shared hosting has no Node, so builds always happen
#      here, never on the server.
#   3. git push the current branch to GitHub.
#   4. rsync each app's dist/ to its own subdomain folder on Hostinger.
#   5. SSH in and update the backend: git fetch + hard reset to the
#      pushed commit, composer install --no-dev, migrate --force,
#      re-create public/storage (gitignored, so a hard reset drops it),
#      cache config/routes/views, restart the queue worker. Wrapped in
#      maintenance mode (php artisan down/up) so nothing serves a
#      half-migrated state mid-deploy.
#
# One-time server setup this script assumes is already done by hand —
# see docs/DEPLOYMENT.md. This script never re-clones the backend and
# never writes the server's backend/.env; it only ever updates an
# existing clone.
#
# Config: copy .env.deploy.example to .env.deploy (gitignored) and
# fill in your real Hostinger/SSH values first.
#
# Flags: --skip-backend --skip-frontend --skip-frontend-admin
#        --skip-push --yes/-y --dry-run --help/-h

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

# ---------- flags ----------
SKIP_BACKEND=false
SKIP_FRONTEND=false
SKIP_FRONTEND_ADMIN=false
SKIP_PUSH=false
ASSUME_YES=false
DRY_RUN=false

for arg in "$@"; do
  case "$arg" in
    --skip-backend) SKIP_BACKEND=true ;;
    --skip-frontend) SKIP_FRONTEND=true ;;
    --skip-frontend-admin) SKIP_FRONTEND_ADMIN=true ;;
    --skip-push) SKIP_PUSH=true ;;
    -y|--yes) ASSUME_YES=true ;;
    --dry-run) DRY_RUN=true ;;
    -h|--help)
      grep '^#' "$0" | sed 's/^#//'
      exit 0
      ;;
    *)
      echo "Unknown flag: $arg" >&2
      exit 1
      ;;
  esac
done

# ---------- output helpers ----------
c_red=$'\033[31m'; c_green=$'\033[32m'; c_yellow=$'\033[33m'; c_blue=$'\033[34m'; c_reset=$'\033[0m'
info()  { echo "${c_blue}==>${c_reset} $*"; }
ok()    { echo "${c_green}[ok]${c_reset} $*"; }
warn()  { echo "${c_yellow}[!]${c_reset} $*"; }
fail()  { echo "${c_red}[fail] $*${c_reset}" >&2; exit 1; }

run() {
  if $DRY_RUN; then
    echo "  [dry-run] $*"
  else
    "$@"
  fi
}

# ---------- load config ----------
if [[ ! -f "$ROOT_DIR/.env.deploy" ]]; then
  fail ".env.deploy not found. Copy .env.deploy.example to .env.deploy and fill in your real Hostinger/SSH values first."
fi
set -a
# shellcheck disable=SC1091
source "$ROOT_DIR/.env.deploy"
set +a

: "${GIT_REMOTE:=origin}"
: "${GIT_BRANCH:=main}"
: "${PHP_BIN:=php}"
: "${COMPOSER_BIN:=composer}"

for var in SSH_HOST SSH_PORT SSH_USER BACKEND_REMOTE_PATH FRONTEND_REMOTE_PATH FRONTEND_ADMIN_REMOTE_PATH; do
  if [[ -z "${!var:-}" ]]; then
    fail "$var is not set in .env.deploy — see .env.deploy.example."
  fi
done

# Built as one always-non-empty array from the start (never interpolate
# a separately-declared possibly-EMPTY array into another array with
# "${arr[@]}") — macOS ships bash 3.2 by default (Apple froze it at the
# last GPLv2 release), which throws "unbound variable" under `set -u`
# when expanding a zero-element array. SSH_OPTS always has >=2 elements
# here, so that bug never triggers, whether or not SSH_KEY_PATH is set.
SSH_OPTS=(-p "$SSH_PORT" -o BatchMode=yes)
RSYNC_SSH="ssh -p $SSH_PORT"
if [[ -n "${SSH_KEY_PATH:-}" ]]; then
  SSH_OPTS+=(-i "$SSH_KEY_PATH")
  RSYNC_SSH="ssh -p $SSH_PORT -i $SSH_KEY_PATH"
fi
ssh_run() { ssh "${SSH_OPTS[@]}" "$SSH_USER@$SSH_HOST" "$@"; }
rsync_to() {
  local src="$1" dst="$2"
  shift 2
  # --chmod=D755,F644 — FORCE web-readable permissions, do not carry the
  # local ones over.
  #
  # 2026-08-20 (TASK-231): the whole site 404'd on every route except `/`
  # for an hour. Cause: .htaccess reached the server as mode 600. rsync -a
  # implies -p, so whatever mode the file happened to have in the working
  # copy is what production got — and LiteSpeed runs as a different user,
  # so a 600 .htaccess is a file it cannot read. It does not warn; it just
  # silently ignores the rewrite rules, and every deep link dies while the
  # deploy reports success.
  #
  # The working copy's modes are an accident of whichever tool last wrote
  # the file (an editor, a script, a umask). They are not a decision, and
  # they must not be able to decide whether production serves. Forcing the
  # mode here fixes it for every file in one place, rather than asking
  # everyone to remember chmod after touching anything under public/.
  #
  # Extra args (e.g. --exclude=/admin) let callers protect destination
  # paths that --delete would otherwise remove — see the frontend call
  # below for why this exists.
  rsync -avz --delete --chmod=D755,F644 "$@" -e "$RSYNC_SSH" "$src" "$SSH_USER@$SSH_HOST:$dst"
}

# ---------- sanity checks ----------
info "Checking SSH connection to $SSH_HOST..."
if ! $DRY_RUN; then
  ssh_run "echo ok" >/dev/null 2>&1 || fail "Could not SSH to $SSH_USER@$SSH_HOST:$SSH_PORT. Check .env.deploy and that your SSH key is authorized (ssh-copy-id -p $SSH_PORT $SSH_USER@$SSH_HOST)."
fi
ok "SSH reachable."

# ---------- git pre-flight: get the work ONTO $GIT_BRANCH ----------
#
# 2026-08-20 (human request: "ทำให้จบในการสั่งที่เดียวได้ไหม") — this block
# replaces two checks that each stopped at the diagnosis and made the human
# go and finish the job by hand.
#
# The second of them was worse than inconvenient, it was DANGEROUS. It
# warned that you were on a different branch and then offered to "continue
# anyway" — but the server does `git reset --hard origin/$GIT_BRANCH`, so
# continuing anyway deploys whatever is on main and silently ships NONE of
# the work you are standing on. A prompt whose "yes" answer is always
# wrong is not a safeguard.
START_BRANCH="$(git rev-parse --abbrev-ref HEAD)"

if [[ -n "$(git status --porcelain)" ]]; then
  echo
  warn "Working tree has uncommitted changes:"
  git status --short
  echo
  # NEVER auto-commit unattended. Committing a file list nobody read is
  # how a .env, a database dump or a debug script reaches a public repo,
  # and --yes exists precisely for contexts where nobody is reading. The
  # interactive path below is a human looking at the list above and
  # answering for it; -y is not.
  if $ASSUME_YES; then
    fail "Uncommitted changes, and --yes is set. Refusing to commit files nobody has looked at. Commit or stash them yourself, then re-run."
  fi
  read -r -p "Commit ALL of the above as one commit and continue? [y/N] " reply
  [[ "$reply" =~ ^[Yy]$ ]] || fail "Aborted. Commit or stash, then re-run."
  read -r -p "Commit message: " commit_msg
  [[ -n "${commit_msg// /}" ]] || fail "Empty commit message — aborted."
  run git add -A
  run git commit -m "$commit_msg"
  ok "Committed."
fi
ok "Working tree clean."

if [[ "$START_BRANCH" != "$GIT_BRANCH" ]]; then
  echo
  warn "You are on '$START_BRANCH', but the server deploys '$GIT_BRANCH' (git reset --hard origin/$GIT_BRANCH)."
  echo "     Deploying without merging would ship $GIT_BRANCH as it stands and none of this branch's work."
  echo
  if ! $ASSUME_YES; then
    read -r -p "Merge '$START_BRANCH' into '$GIT_BRANCH' and deploy that? [Y/n] " reply
    [[ -z "$reply" || "$reply" =~ ^[Yy]$ ]] || fail "Aborted — nothing was merged, pushed or deployed."
  fi

  # Push the feature branch FIRST, before touching $GIT_BRANCH. If anything
  # below goes wrong the work is already safe on the remote, and the branch
  # stays on GitHub afterwards so the merge has a reviewable other side.
  info "Pushing '$START_BRANCH' to $GIT_REMOTE..."
  run git push -u "$GIT_REMOTE" "$START_BRANCH"

  info "Merging '$START_BRANCH' into '$GIT_BRANCH'..."
  run git checkout "$GIT_BRANCH"
  # --ff-only: if local $GIT_BRANCH has diverged from the remote, that is a
  # real situation needing a real decision, not something to resolve inside
  # a deploy script.
  run git pull --ff-only "$GIT_REMOTE" "$GIT_BRANCH"

  if ! $DRY_RUN; then
    # --no-ff so the release is ONE identifiable commit on $GIT_BRANCH —
    # the thing you revert if this deploy turns out to be a mistake.
    if ! git merge --no-ff -m "Merge branch '$START_BRANCH'" "$START_BRANCH"; then
      git merge --abort || true
      git checkout "$START_BRANCH" || true
      fail "Merge conflict between '$START_BRANCH' and '$GIT_BRANCH'. Nothing was deployed and you are back on '$START_BRANCH'. Resolve it by hand, then re-run."
    fi
  fi
  ok "Merged into $GIT_BRANCH."
fi

CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"

# What is about to run against the LIVE DATABASE, shown before the one
# confirmation below rather than as a separate prompt.
#
# `migrate --force` further down is the single most irreversible thing this
# script does, and until now the only way to know what it would run was to
# SSH in and look. A schema change you did not expect is exactly the moment
# to answer "no" — but only if you were told. Read-only, and `|| true`
# because a failure to LIST migrations must never be a reason not to deploy.
PENDING_MIGRATIONS=""
if [[ "$SKIP_BACKEND" != true ]] && ! $DRY_RUN; then
  PENDING_MIGRATIONS="$(ssh_run "cd '$BACKEND_REMOTE_PATH' && $PHP_BIN artisan migrate:status --pending 2>/dev/null | grep -Ei 'pending' || true" 2>/dev/null || true)"
fi

COMMIT_SHA="$(git rev-parse --short HEAD)"
COMMIT_MSG="$(git log -1 --pretty=%s)"
echo
echo "Deploying commit ${c_yellow}$COMMIT_SHA${c_reset} — \"$COMMIT_MSG\""
echo "  backend:         $([ "$SKIP_BACKEND" = true ] && echo skipped || echo "$BACKEND_REMOTE_PATH")"
echo "  frontend:        $([ "$SKIP_FRONTEND" = true ] && echo skipped || echo "$FRONTEND_REMOTE_PATH")"
echo "  frontend-admin:  $([ "$SKIP_FRONTEND_ADMIN" = true ] && echo skipped || echo "$FRONTEND_ADMIN_REMOTE_PATH")"
echo "  github push:     $([ "$SKIP_PUSH" = true ] && echo skipped || echo "$GIT_REMOTE/$GIT_BRANCH")"
if [[ -n "$PENDING_MIGRATIONS" ]]; then
  echo
  echo "  ${c_yellow}migrations that will run on the LIVE database:${c_reset}"
  echo "$PENDING_MIGRATIONS" | sed 's/^/    /'
  echo "  ${c_yellow}Back the database up first if any of these are unfamiliar — a migration cannot be un-run.${c_reset}"
fi
echo
if ! $ASSUME_YES; then
  read -r -p "Proceed? [y/N] " reply
  [[ "$reply" =~ ^[Yy]$ ]] || fail "Aborted."
fi

# ---------- 1. build frontends ----------
# frontend/.env and frontend-admin/.env are committed dev defaults
# (http://agent.localhost:8010 / http://admin.localhost:8010 — see
# those files' own comments). `vite build` runs in production mode and
# layers .env.production ON TOP of .env — without a real
# .env.production here, a production build would silently embed the
# dev localhost API URL and every API call on the live site would fail
# with no build-time error. Refuse to build rather than ship that.
if [[ "$SKIP_FRONTEND" != true && ! -f "$ROOT_DIR/frontend/.env.production" ]]; then
  fail "frontend/.env.production is missing. Copy frontend/.env.production.example to frontend/.env.production and fill in the real production API URL first (see docs/DEPLOYMENT.md)."
fi
if [[ "$SKIP_FRONTEND_ADMIN" != true && ! -f "$ROOT_DIR/frontend-admin/.env.production" ]]; then
  fail "frontend-admin/.env.production is missing. Copy frontend-admin/.env.production.example to frontend-admin/.env.production and fill in the real production API URL first (see docs/DEPLOYMENT.md)."
fi

if [[ "$SKIP_FRONTEND" != true ]]; then
  info "Building frontend/ (type-check + vite build)..."
  run bash -c "cd '$ROOT_DIR/frontend' && npm ci && npm run build"
  ok "frontend/ build complete."
fi

if [[ "$SKIP_FRONTEND_ADMIN" != true ]]; then
  info "Building frontend-admin/ (type-check + vite build)..."
  run bash -c "cd '$ROOT_DIR/frontend-admin' && npm ci && npm run build"
  ok "frontend-admin/ build complete."
fi

# ---------- 2. push to GitHub ----------
if [[ "$SKIP_PUSH" != true ]]; then
  info "Pushing $GIT_BRANCH to $GIT_REMOTE..."
  run git push "$GIT_REMOTE" "$GIT_BRANCH"
  ok "Pushed."
fi

# ---------- 3. deploy frontends (static rsync) ----------
if [[ "$SKIP_FRONTEND" != true ]]; then
  info "Uploading frontend/dist/ -> $FRONTEND_REMOTE_PATH ..."
  # FRONTEND_REMOTE_PATH is the domain ROOT (Agent Portal lives there, not
  # a subfolder — docs/DEPLOYMENT.md step 3), and admin/ + api both live
  # as siblings inside that same public_html. --delete on a plain rsync
  # here would erase both on every deploy: frontend/dist/ never contains
  # an admin/ or api/ path, so rsync would treat them as "extraneous" and
  # remove them — admin/ silently comes back a few lines down (the very
  # next rsync recreates it from frontend-admin/dist/), but nothing ever
  # recreates the api/ symlink to backend/public (docs/DEPLOYMENT.md
  # step 4), so every deploy quietly broke the API until the next manual
  # fix. Protect both explicitly.
  #
  # ADR-039 (2026-08-21) adds a THIRD thing to protect: the /backend
  # symlink that serves the Laravel front controller same-origin. It is
  # exactly as invisible to frontend/dist/ as api/ is, and losing it takes
  # the whole agent portal down rather than just the API — every
  # authenticated request goes through it once ADR-039 step 3 lands.
  run rsync_to "$ROOT_DIR/frontend/dist/" "$FRONTEND_REMOTE_PATH/" --exclude=/admin --exclude=/api --exclude=/backend
  ok "frontend deployed."
fi

if [[ "$SKIP_FRONTEND_ADMIN" != true ]]; then
  info "Uploading frontend-admin/dist/ -> $FRONTEND_ADMIN_REMOTE_PATH ..."
  # ADR-039 (2026-08-21) — the admin app gets its own same-origin Laravel
  # mount at <admin docroot>/backend, and this rsync is the one that would
  # delete it.
  #
  # This line had NO --exclude at all until now, which was correct while
  # nothing but the built app lived in that folder. The comment on the
  # agent rsync above describes what happens when that assumption stops
  # holding: "every deploy quietly broke the API until the next manual
  # fix". That already happened once here with api/. Adding the exclude in
  # the SAME change that creates the symlink, so there is never a window
  # where a deploy would wipe it.
  run rsync_to "$ROOT_DIR/frontend-admin/dist/" "$FRONTEND_ADMIN_REMOTE_PATH/" --exclude=/backend
  ok "frontend-admin deployed."
fi

# ---------- 4. deploy backend over SSH ----------
if [[ "$SKIP_BACKEND" != true ]]; then
  info "Deploying backend on $SSH_HOST (git reset --hard + composer + migrate)..."
  REMOTE_CMD=$(cat <<EOF
set -e
cd '$BACKEND_REMOTE_PATH'
$PHP_BIN artisan down --render="errors::503" || true
git fetch origin
git checkout '$GIT_BRANCH'
git reset --hard 'origin/$GIT_BRANCH'
$COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction
$PHP_BIN artisan migrate --force
# 2026-08-20 — public/storage is GITIGNORED, so \`git reset --hard\` above
# never restores it. On a server where it is missing, every uploaded logo,
# avatar, banner and theme asset 404s: the file is on disk under
# storage/app/public but nothing web-servable points at it, so the request
# falls through to Laravel's router and renders a 404. That is exactly what
# happened on production (theme nav logo, 2026-08-20).
#
# NOT \`php artisan storage:link\` — DELIBERATELY. Hostinger disables both
# symlink() and exec() in PHP, so that command dies with
# "Call to undefined function Illuminate\Filesystem\exec()" (Laravel's
# link() falls back to exec('ln -s') when symlink() is missing, and then
# that is disabled too). Verified on this server, 2026-08-20.
#
# The SHELL's \`ln\` has no such restriction, so the link is made here
# instead. Idempotent: an existing correct symlink is left alone, and a
# REAL directory at that path is reported rather than deleted — blowing
# away a folder somebody put there on purpose is not this script's call.
if [ -L public/storage ]; then
  echo '  public/storage symlink already present.'
elif [ -e public/storage ]; then
  echo '  WARNING: public/storage exists and is NOT a symlink — left untouched. Uploaded files may 404.'
else
  ln -s "\$(pwd)/storage/app/public" public/storage && echo '  public/storage symlink created.'
fi
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
$PHP_BIN artisan queue:restart
$PHP_BIN artisan up
EOF
)
  if $DRY_RUN; then
    echo "  [dry-run] ssh $SSH_USER@$SSH_HOST <<'REMOTE'"
    echo "$REMOTE_CMD" | sed 's/^/  [dry-run]   /'
    echo "  [dry-run] REMOTE"
  else
    if ! ssh_run "$REMOTE_CMD"; then
      warn "Backend deploy failed mid-way — site may be in maintenance mode. SSH in, check the error, then run: $PHP_BIN artisan up"
      exit 1
    fi
  fi
  ok "backend deployed."
fi

# ---------- 6. post-deploy smoke check ----------
#
# WHY THIS EXISTS (2026-08-20, TASK-231). A change to the frontends'
# .htaccess killed the SPA fallback rewrite on BOTH apps. Every deep route
# and every hard refresh 404'd — /login, /products, a customer's product
# share link, the whole admin app below its root — while `/` still
# returned 200 and this script printed "Deploy complete" and exited 0.
#
# Nothing in the deploy was wrong from the deployer's point of view: files
# copied, migrations ran, caches rebuilt. The site was simply broken, and
# the first thing to notice was a human clicking their own share link.
#
# So: after everything else succeeds, actually ASK THE SITE. A deep route
# is the check that matters — the root is served by index.html existing
# and proves nothing about the rewrite.
#
# Set SMOKE_URLS in .env.deploy, space-separated. Deliberately not derived
# from the remote paths: the admin app is served from a SUBDIRECTORY on
# disk but a SUBDOMAIN over HTTP, so there is no honest way to guess it.
# Unset skips the check loudly rather than silently.
if [[ -z "${SMOKE_URLS:-}" ]]; then
  warn "SMOKE_URLS is not set in .env.deploy — skipping the post-deploy check."
  warn "Add e.g.  SMOKE_URLS=\"https://example.com/login https://admin.example.com/academy\""
else
  echo
  info "Smoke-checking deep routes (these prove the SPA fallback survived)..."
  SMOKE_FAILED=false
  for smoke_url in $SMOKE_URLS; do
    if $DRY_RUN; then
      echo "  [dry-run] curl $smoke_url"
      continue
    fi
    smoke_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -L "$smoke_url" || echo 000)"
    if [[ "$smoke_code" == "200" ]]; then
      echo "  ✓ $smoke_code  $smoke_url"
    else
      echo "  ✗ $smoke_code  $smoke_url"
      SMOKE_FAILED=true
    fi
  done
  if $SMOKE_FAILED; then
    echo
    warn "A deep route did NOT return 200. The files are deployed but the site is not serving them."
    warn "Most likely the .htaccess SPA fallback: any request that is not a real file must rewrite to /index.html."
    warn "Check:  ssh -p \$SSH_PORT \$SSH_USER@\$SSH_HOST \"cat '$FRONTEND_REMOTE_PATH/.htaccess'\""
    exit 1
  fi
  ok "deep routes reachable."
fi

echo
ok "Deploy complete — commit $COMMIT_SHA is live."

# You started somewhere else and this script moved you. Say so, once —
# silently leaving someone on a different branch than the one they were
# working on is how the next commit lands in the wrong place.
if [[ "$START_BRANCH" != "$GIT_BRANCH" ]]; then
  echo "     You are now on '$GIT_BRANCH' (you started on '$START_BRANCH')."
  echo "     Back to it with:  git checkout $START_BRANCH"
fi
