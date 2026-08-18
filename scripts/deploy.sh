#!/usr/bin/env bash
#
# scripts/deploy.sh — Sync Vision Agent one-command deploy.
#
# Run via `npm run deploy` from the repo root. What it does, in order:
#   1. Refuses to run with uncommitted changes (deploy always ships an
#      exact commit, never working-tree drift).
#   2. Builds frontend/ and frontend-admin/ locally (vite build, which
#      also type-checks — see each app's package.json "build" script).
#      Hostinger shared hosting has no Node, so builds always happen
#      here, never on the server.
#   3. git push the current branch to GitHub.
#   4. rsync each app's dist/ to its own subdomain folder on Hostinger.
#   5. SSH in and update the backend: git fetch + hard reset to the
#      pushed commit, composer install --no-dev, migrate --force,
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
  # Extra args (e.g. --exclude=/admin) let callers protect destination
  # paths that --delete would otherwise remove — see the frontend call
  # below for why this exists.
  rsync -avz --delete "$@" -e "$RSYNC_SSH" "$src" "$SSH_USER@$SSH_HOST:$dst"
}

# ---------- sanity checks ----------
info "Checking SSH connection to $SSH_HOST..."
if ! $DRY_RUN; then
  ssh_run "echo ok" >/dev/null 2>&1 || fail "Could not SSH to $SSH_USER@$SSH_HOST:$SSH_PORT. Check .env.deploy and that your SSH key is authorized (ssh-copy-id -p $SSH_PORT $SSH_USER@$SSH_HOST)."
fi
ok "SSH reachable."

if [[ -n "$(git status --porcelain)" ]]; then
  fail "Working tree has uncommitted changes. Commit or stash before deploying."
fi
ok "Working tree clean."

CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$CURRENT_BRANCH" != "$GIT_BRANCH" ]]; then
  warn "You're on branch '$CURRENT_BRANCH', but GIT_BRANCH is '$GIT_BRANCH' in .env.deploy."
  if ! $ASSUME_YES; then
    read -r -p "Continue anyway? [y/N] " reply
    [[ "$reply" =~ ^[Yy]$ ]] || fail "Aborted."
  fi
fi

COMMIT_SHA="$(git rev-parse --short HEAD)"
COMMIT_MSG="$(git log -1 --pretty=%s)"
echo
echo "Deploying commit ${c_yellow}$COMMIT_SHA${c_reset} — \"$COMMIT_MSG\""
echo "  backend:         $([ "$SKIP_BACKEND" = true ] && echo skipped || echo "$BACKEND_REMOTE_PATH")"
echo "  frontend:        $([ "$SKIP_FRONTEND" = true ] && echo skipped || echo "$FRONTEND_REMOTE_PATH")"
echo "  frontend-admin:  $([ "$SKIP_FRONTEND_ADMIN" = true ] && echo skipped || echo "$FRONTEND_ADMIN_REMOTE_PATH")"
echo "  github push:     $([ "$SKIP_PUSH" = true ] && echo skipped || echo "$GIT_REMOTE/$GIT_BRANCH")"
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
  run rsync_to "$ROOT_DIR/frontend/dist/" "$FRONTEND_REMOTE_PATH/" --exclude=/admin --exclude=/api
  ok "frontend deployed."
fi

if [[ "$SKIP_FRONTEND_ADMIN" != true ]]; then
  info "Uploading frontend-admin/dist/ -> $FRONTEND_ADMIN_REMOTE_PATH ..."
  run rsync_to "$ROOT_DIR/frontend-admin/dist/" "$FRONTEND_ADMIN_REMOTE_PATH/"
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

echo
ok "Deploy complete — commit $COMMIT_SHA is live."
