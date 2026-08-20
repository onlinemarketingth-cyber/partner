#!/usr/bin/env bash
#
# scripts/release-commits-2.sh — second batch on release/2026-08-20-commission.
#
# WHY THIS FILE EXISTS AT ALL: git writes cannot be made from the Claude
# sandbox. The folder is mounted without permission to unlink, and git must
# delete .git/index.lock after every index write — so `git add` fails, stages
# nothing, and leaves a stale lock behind. This script is exactly the commands
# that would otherwise have been run directly. Run it yourself:
#
#     bash scripts/release-commits-2.sh
#     git push
#
# HONEST LIMITATION, same as batch 1: two files carry more than one task.
#   - scripts/deploy.sh          → storage-link fix only (clean)
#   - frontend/src/router/index.ts → TASK-218 only (clean)
# This batch happens to split cleanly; nothing is smeared across commits.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

BRANCH="release/2026-08-20-commission"

if [[ "$(git rev-parse --abbrev-ref HEAD)" != "$BRANCH" ]]; then
  echo "Expected to be on $BRANCH, but HEAD is $(git rev-parse --abbrev-ref HEAD)." >&2
  echo "Run: git checkout $BRANCH" >&2
  exit 1
fi

# Left behind by the sandbox when it copied backend sources out to run
# PHPUnit. Not part of the project.
rm -f _to_delete_sync.tgz

commit() {
  local msg="$1"; shift
  git add -- "$@"
  if git diff --cached --quiet; then
    echo "  (nothing staged for: ${msg%%$'\n'*}) — skipped"
    return
  fi
  git commit -q -m "$msg"
  echo "  ✓ ${msg%%$'\n'*}"
}

echo "==> committing batch 2 on $BRANCH"

commit "fix(admin): regroup the admin nav — companies and commission settings

Two nav moves the human asked for on 2026-08-20, both pure regrouping:
no route name, no path and no permission changes, so every existing link
and bookmark keeps working.

1. 'จัดการบริษัท' was its own top-level pillar. It is now the first
   sub-item of 'ตั้งค่าระบบ', immediately before 'ธีม / แบรนด์' — the
   order asked for, and the one that reads correctly: pick the company,
   then style it. Its Super-Admin-only flag moved from the pillar to the
   sub-item, because the pillar must stay open to Company Admin (they
   need 'ธีม / แบรนด์') and a pillar-level gate cannot express 'only some
   of my sub-items are restricted'. Same per-sub-item mechanism already
   used by 'ตั้งค่า Email SMTP'.

2. 'แผนคอมมิชชั่น' was a top-level pillar sitting right next to
   'Commission'. Two neighbouring pillars about the same subject — one
   holding the money that was paid, one holding the rules that decide it
   — is a distinction the top bar could not express. They are now one
   pillar with two sub-items ('จ่ายคอมมิชชั่น' and 'ตั้งค่า'), which is
   what row 2 exists for. The first sub-item is relabelled too: leaving
   it as 'Commission' beside 'ตั้งค่า' would name the parent twice and
   still never say the page holds the payout ledger.

Top-level pillars: 11 -> 9." \
  frontend-admin/src/design-system/components/AdminNavigation.vue

commit "feat(theme): platform-wide shared colour presets (TASK-217)

theme_presets.company_id becomes NULLABLE, and NULL now MEANS something:
ชุดกลาง, a palette owned by the platform rather than one tenant. Every
company sees it and may apply it; applying one writes the colours onto
whichever company the caller is acting in.

Only a Super Admin can create, rename or delete a shared preset. A
Company Admin may see and apply one but gets a 422 on either write verb —
it is in use by every other tenant and nothing on their screen would say
so. The rule is enforced twice, in ThemePresetPolicy and again in
ThemePresetService, for the same reason the system-preset rule is: a
Policy guards a route, the Service guards the method.

NULL rather than an is_shared flag: a boolean beside a still-NOT-NULL
company_id would leave every shared row owning a company it does not
belong to, and every query would have to remember to ignore that column.
NULL says the row has no owner, which is true, and makes a wrong query
fail loudly instead of quietly returning a tenant-labelled global.

BR-6 is not weakened. A preset holds only the colour surface
(COLOR_FIELDS — hex values, gradient configs, a shadow keyword) and never
a name, logo, client or price. Owned presets keep every existing
guarantee: SharedOrTenantScope still filters them by company and the
Policy still refuses company B's row to company A. Tests cover exactly
that — widening the list to include shared rows must not drag another
tenant's presets in with them.

SharedOrTenantScope is new because plain TenantScope's
'where company_id = :own' excludes NULL, which would have hidden every
shared preset from the admins it exists for — including at
route-model-binding time, turning each one into a 404.

Not done, deliberately: the five is_system designed palettes stay cloned
per company. Collapsing them into five global rows is a data migration
across every tenant to fix a duplication nobody has complained about, and
does not belong on the same day this column changes shape.

php artisan test: 1641 passed (6162 assertions), up from 1631." \
  backend/database/migrations/2026_09_05_090000_make_theme_presets_company_id_nullable.php \
  backend/app/Models/Scopes/SharedOrTenantScope.php \
  backend/app/Models/Scopes/TenantScope.php \
  backend/app/Models/ThemePreset.php \
  backend/app/Services/Theme/ThemePresetService.php \
  backend/app/Http/Controllers/Api/V1/ThemePresetController.php \
  backend/app/Http/Requests/Theme/StoreThemePresetRequest.php \
  backend/app/Http/Requests/Theme/Concerns/ResolvesPresetCompany.php \
  backend/app/Http/Resources/ThemePresetResource.php \
  backend/app/Policies/ThemePresetPolicy.php \
  backend/tests/Feature/Theme/ThemePresetTest.php \
  frontend-admin/src/views/ThemeSettingsView.vue \
  docs/tasks/TASK-217-shared-theme-presets.md

commit "fix(deploy): re-create public/storage on every backend deploy

public/storage is gitignored, so the deploy's 'git reset --hard' never
restores it. On production it was missing, and every uploaded file 404ed:
the bytes were on disk under storage/app/public but nothing web-servable
pointed at them, so requests fell through to Laravel's router. Found via
the theme nav logo; avatars, brand logos, storefront banners and
announcement images were all equally unreachable.

NOT 'php artisan storage:link' — that command cannot work on this host.
Hostinger disables both symlink() and exec() in PHP, so Laravel's
Filesystem::link() falls through to exec('ln -s') and dies with
'Call to undefined function Illuminate\\Filesystem\\exec()'. Verified on
the server, 2026-08-20. The shell's own ln has no such restriction.

Idempotent: an existing correct symlink is left alone, and a REAL
directory at that path is reported rather than deleted — removing a
folder somebody put there on purpose is not this script's call." \
  scripts/deploy.sh

commit "fix(agent): show Super Admin a notice instead of an empty dashboard (TASK-218)

A Super Admin opening the Agent Portal saw a dashboard rendered from
their own admin identity — zero XP, no team, no orders — which reads as a
broken app rather than as 'wrong role for this door'. They now get one
line saying so and are sent to the Admin app after a second, with a
manual link underneath in case the redirect never fires.

The underlying cause is documented in the view's docblock and in
docs/tasks/TASK-218: on production both apps call the same API host, so
the Sanctum session cookie is issued with a parent-domain scope and every
subdomain shares one login identity. Locally this was fixed on 2026-08-02
by giving each app its own API hostname; that fix was never carried over
to production. THIS COMMIT DOES NOT FIX THAT — it removes the most
confusing symptom while the real change (per-host API origins, host-only
cookies) stays on the table.

Not a security boundary, and must not be described as one: a client-side
route guard protects nothing, and every endpoint is already gated by
server-side Policies and Abilities.

Scoped to non-public routes on purpose. The public token pages
(/p/:token, /pay/:token, /l/:token) stay open to anyone holding the link,
including a Super Admin checking that their own link works — which is the
single most likely reason they would open this app at all. Blocking those
would recreate the 'opened my own link and it looked broken' complaint
the /register?ref= exception already exists to prevent.

super_admin only, not company_admin: nobody has reported the same
confusion for that role, and locking a role out of a whole app on a guess
is not a call to make on the human's behalf (BR-7)." \
  frontend/src/views/SuperAdminNoticeView.vue \
  frontend/src/router/index.ts \
  docs/tasks/TASK-218-super-admin-agent-portal-lock.md

# Catch-all: anything the explicit lists above missed still belongs on
# this branch. Loud, so it is never a silent surprise.
if [[ -n "$(git status --porcelain)" ]]; then
  echo
  echo "==> leftover files not matched by any commit above:"
  git status --porcelain
  git add -A
  git commit -q -m "chore: remaining files from the 2026-08-20 batch"
  echo "  ✓ chore: remaining files"
fi

echo
echo "==> done. Working tree:"
git status --short
echo
echo "Next:  git push"
