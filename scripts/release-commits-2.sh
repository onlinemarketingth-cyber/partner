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
# HONEST LIMITATION, same as batch 1. Most of this batch splits cleanly,
# but THREE spec files carry changes from two tasks each, and git commits
# whole files — so their TASK-227 edits land in the EARLIER commit whose
# message does not mention them:
#   - frontend-admin/.../ThemeSettingsView.spec.ts        → in the TASK-225 commit
#   - frontend-admin/.../useAuthenticatedMedia.spec.ts    → in the TASK-224 commit
#   - frontend/.../useAuthenticatedMedia.spec.ts          → in the TASK-224 commit
# In each case the TASK-227 part is the same two lines: dropping a
# localStorage.clear() or an unused @ts-expect-error. Splitting them would
# need index surgery this script cannot do; saying so is the better trade.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

# 2026-08-20 — accepts main too. The release branch was merged into main
# partway through this batch (deploy.sh's new pre-flight does that), so
# insisting on the feature branch would refuse to run on the very branch
# the work now lives on. Anything OTHER than these two is still refused:
# committing this file list onto an unrelated branch is not a mistake worth
# being helpful about.
HEAD_BRANCH="$(git rev-parse --abbrev-ref HEAD)"

case "$HEAD_BRANCH" in
  release/2026-08-20-commission|main) ;;
  *)
    echo "HEAD is '$HEAD_BRANCH'. Expected 'main' or 'release/2026-08-20-commission'." >&2
    echo "Run: git checkout main" >&2
    exit 1
    ;;
esac

echo "==> on '$HEAD_BRANCH'" 

# Left behind by the sandbox when it copied backend sources out to run
# PHPUnit against real PHP. Not part of the project — and batch 1's
# catch-all commit swept it into git, so it needs removing AND ignoring,
# not just deleting.
rm -f _to_delete_sync.tgz
if ! grep -qx '_to_delete_sync.tgz' .gitignore 2>/dev/null; then
  printf '\n# sandbox scratch tarball (see scripts/release-commits.sh)\n_to_delete_sync.tgz\n' >> .gitignore
fi

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

echo "==> committing batch 2"

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

commit "fix(media): stop uploads that display as broken, and add an audit for the rest (TASK-220)

Follow-up to the public/storage fix: a full pass over all 18 upload
features asking what ELSE can make an uploaded file fail to display.
Production itself is healthy — APP_URL is correct, all four theme logos
return 200 with the right content-type and non-zero length, and stored
files are 0644 — so this is the code-level residue.

Two real defects:

1. Sixteen call sites built the stored filename from
   getClientOriginalExtension(), which reads the CLIENT-supplied name and
   returns an EMPTY STRING when the upload had none. An upload named
   'logo' with no dot was stored at '<uuid>.' — a path ending in a bare
   dot, served with no usable Content-Type, rendering nothing, with the
   file present on disk and nothing in any log. ModuleLessonService
   already solved this correctly for Academy files (TASK-093) and only
   there; that method is now App\Support\Media\StoredFileName and every
   call site uses it. It guesses from the real MIME type first, falls back
   to the client extension stripped to [a-z0-9], then to 'bin' — so the
   result can never be empty and a '.' or '..' cannot survive.

2. ProductCatalogMediaResource calls route() on two names that do not
   exist in routes/api.php. Its own comment claimed it was 'not reachable
   until those routes exist'; it is — ProductCatalogItemResource embeds it
   and the controller eager-loads media, so one product_catalog_media row
   would turn the whole catalog-item endpoint into a 500. Nothing creates
   those rows through the API today, so it has never fired. Defused with
   Route::has() rather than deleted: when TASK-213's routes land the URLs
   start working with no further change.

Plus `php artisan media:audit` (read-only). The last round was found
because a human happened to notice one broken logo; every other file was
equally broken and nobody had looked. It walks all 18 path columns and
reports files that are missing, zero bytes, or carry the bare-dot path,
and separately whether public/storage exists — separately, because a
missing symlink makes every public file unreachable however healthy the
rows are, and one combined 'no problems' verdict would be a lie.

It never deletes a row or repairs anything. A command that tidies away
rows whose file is missing is a command that deletes a client's PDPA
document the one time a disk fails to mount.

php artisan test: 1647 passed (6175 assertions), up from 1641." \
  backend/app/Support/Media/StoredFileName.php \
  backend/app/Console/Commands/AuditMediaFilesCommand.php \
  backend/tests/Feature/Media/AuditMediaFilesCommandTest.php \
  backend/app/Http/Resources/ProductCatalogMediaResource.php \
  backend/app/Services/Order/OrderService.php \
  backend/app/Services/Catalog/StorefrontBannerService.php \
  backend/app/Services/Catalog/ProductMediaService.php \
  backend/app/Services/Catalog/ProductSpecAttachmentService.php \
  backend/app/Services/Catalog/ProductSalesMaterialService.php \
  backend/app/Services/Catalog/BrandService.php \
  backend/app/Services/Platform/UserProfileService.php \
  backend/app/Services/Engagement/AnnouncementService.php \
  backend/app/Services/Theme/ThemeService.php \
  backend/app/Services/Customer/ClientDocumentService.php \
  backend/app/Services/Academy/ModuleLessonService.php \
  docs/tasks/TASK-220-upload-display-audit.md

commit "feat(academy): make cert tiers manageable, and fix the 500 that hid them (TASK-221)

There was no way to create a cert tier. GET /cert-tiers was the only route,
and the rows came from CatalogSeeder — a DEV-ONLY seeder that also inserts
placeholder brands and products, so it never ran on production. Production
had ZERO tiers (verified: {\"data\":[]}), and the symptom surfaced two
screens away: the Academy Section form would not save, because its Cert
tier <select> is required and had nothing in it.

Adds store/update/destroy behind CertTierPolicy, and an admin screen —
Academy's fifth tab, Super Admin only.

Super-Admin-only because cert_tiers has NO company_id: every company shares
one list. A Company Admin renaming Basic would rename it for every tenant,
and deleting a tier would reach into other companies commission rules,
modules and certifications. Reading stays open to every role, Agent
included — the Agent Portal renders Academy progress against these.

Also fixes a TASK-209 regression found on the way: CertTierController
called CompanyScopeFilter with a comment claiming cert tiers are
per-company. They are not, and there is no such column — the filter
appended where company_id = ? to a table without it, so any caller passing
?company_id= got a 500. Nothing in the app sends it, which is the only
reason it was never seen. Checked every other CompanyScopeFilter caller:
cert_tiers is the only table missing the column.

Two guards worth naming. Eleven tables point at cert_tiers with
restrictOnDelete, so deleting one in use would surface as a 500 carrying an
SQLSTATE; the Service counts the references first and answers 422 with a
sentence naming what is still using it. And key becomes immutable once
anything depends on the tier — it is the handle server code matches on
(where key = basic), so moving it under live data breaks call sites that
cannot be found by looking at this table. The display name stays editable
either way.

BR-7: no tier names, keys or ordering are defaulted anywhere. CLAUDE.md §2
documents Basic (mandatory) -> Intermediate -> High; the empty state cites
it as a hint and fills in nothing.

php artisan test: 1660 passed (6215 assertions), up from 1647.

NOT clicked in a browser yet — the admin dev server is stuck on an
unrelated Vite dep-optimizer 503 for pdfjs-dist. vue-tsc, eslint and a
direct SFC compile are all clean. See docs/tasks/TASK-221." \
  backend/app/Services/Academy/CertTierService.php \
  backend/app/Policies/CertTierPolicy.php \
  backend/app/Http/Requests/Academy/StoreCertTierRequest.php \
  backend/app/Http/Requests/Academy/UpdateCertTierRequest.php \
  backend/app/Http/Controllers/Api/V1/CertTierController.php \
  backend/routes/api.php \
  backend/tests/Feature/Academy/CertTierManagementTest.php \
  frontend-admin/src/views/CertTierPanel.vue \
  frontend-admin/src/views/AcademyManagementView.vue \
  docs/tasks/TASK-221-cert-tier-management.md

commit "fix(uploads): a Super Admin has no company, so large uploads 500'd (TASK-222)

Reported from production on a 198 MB video: POST /uploads/init answered
500 before a single byte was sent. NOT a host limit — the server allows
2048M per request and the transport sends 5 MB chunks precisely so no PHP
limit ever has to be raised.

users.company_id is NULL for a Super Admin, deliberately and by design
(the users migration calls them the one legitimate exception to NOT NULL).
ChunkedUploadController::init() handed that null to
VideoProcessingSettingService::forCompany(int \$companyId) — a fatal
TypeError. forCompany() now accepts ?int and answers with the platform
defaults from config/media.php, which is the same answer it already gave
for a company that never customised its settings.

chunked_uploads.company_id becomes nullable for the same actor. NULL means
something here: staged by a platform operator, not yet bound to a company.
A chunked upload is a temporary pile of bytes; the company binding happens
later at the create endpoint the token is handed to, which validates it
properly.

BR-6 is not weakened, and it is worth stating because it looks like it
should be: TenantScope narrows a Company Admin with where company_id =
:own, which excludes a NULL row — so a tenant cannot see, append to, or
consume a platform operator's staging file, token or no token. Pinned by
test_a_company_admin_cannot_touch_a_super_admins_unbound_upload.

The same null was already recorded in RoleGateCharacterizationTest as
settings.video_processing.view => [403, 200, 500, 200], with a TODO:
CONFIRM and the note 'Recorded, NOT fixed (TASK-185 §4)'. It stayed unfixed
until it surfaced through a different door and hit a human in production.
That line is now [403, 200, 200, 200] — the characterization suite doing
exactly the job it exists for.

Rejected for now: requiring a Super Admin to name a company at
/uploads/init. It matches the pattern used elsewhere, but the chunked
transport lives in api/client.ts behind six call sites, several of which
legitimately have no company in hand (a lesson upload derives its company
from the module in the URL). Six changes to work around one null.

php artisan test: 1662 passed (6222 assertions), up from 1660." \
  backend/app/Services/Catalog/VideoProcessingSettingService.php \
  backend/app/Http/Controllers/Api/V1/ChunkedUploadController.php \
  backend/database/migrations/2026_09_06_090000_make_chunked_uploads_company_id_nullable.php \
  backend/tests/Feature/Catalog/ChunkedUploadTest.php \
  backend/tests/Feature/Authorization/RoleGateCharacterizationTest.php \
  docs/tasks/TASK-222-super-admin-large-upload.md

commit "fix(media): stop one card revoking another card's image blob (TASK-223)

Reported from production in Safari: product images showed sometimes and
not others. The files were fine — both media rows answered 200 image/jpeg
with real byte counts when fetched directly. The bug was in
useAuthenticatedMedia.

Protected media cannot be an <img src> (the session cookie is not sent on
a subresource), so it is fetched and turned into a blob URL. The trigger
is two components showing the SAME image at once, which the product
screens do constantly — the recommended card and the grid below it can be
the same product.

Two defects compounded:

1. No de-duplication of in-flight fetches. The second card found an empty
   cache while the first request was still open, started its own, and its
   objectUrlCache.set() OVERWROTE the first entry. The first card was left
   displaying a blob the cache no longer knew about — a leak and a
   dangling handle.

2. The reference count was incremented AFTER the await. Between a fetch
   starting and finishing the count was zero, so any release() in that
   window revoked a blob another card was already showing. That is the
   'sometimes'.

Now: in-flight promises are shared, the cache is written once and never
overwritten, retain() runs before the await and the previous url is
released after (so re-loading the same url can never dip to zero), a blob
whose last holder unmounted mid-flight is revoked instead of cached, and a
late-resolving load cannot overwrite a newer one.

Safari surfaced it and Chrome mostly did not. That is a timing hint, not a
difference in correctness — a revoked object URL is invalid everywhere.

Both copies of the composable are changed; frontend and frontend-admin
keep them in sync deliberately (CI-001/CI-002).

TASK-224 rides along in the same commit because it touches the same two
files and only makes sense on top of this change: a failed fetch used to
leave a dead red triangle until the component happened to remount. Now a
TRANSIENT failure (dropped connection, 408, 429, 5xx) is retried twice
with a short backoff, a PERMANENT one (404/403/401) is not — retrying an
answer costs three requests per image to arrive at the same place, and a
grid of twelve missing thumbnails would make that thirty-six — and the
error placeholder became a tappable retry button for the cases auto-retry
deliberately skips: a 404 the admin has since re-uploaded, a 403 a
re-login cleared. The two error messages are now distinguishable, so
'file is gone' no longer reads the same as 'network hiccuped'.

Proven rather than assumed: the spec has ten cases. Three fail against
the implementation before TASK-223 (including the one describing the
reported symptom) and five more fail against the implementation before
TASK-224. Run npm run test:unit in both apps — the sandbox
cannot run this project's vitest (rolldown native binding is a macOS
build), so the spec was verified against a clean vue+vitest install with
the real composable." \
  frontend/src/composables/useAuthenticatedMedia.ts \
  frontend/src/composables/__tests__/useAuthenticatedMedia.spec.ts \
  frontend/src/design-system/components/AuthenticatedMedia.vue \
  frontend-admin/src/composables/useAuthenticatedMedia.ts \
  frontend-admin/src/composables/__tests__/useAuthenticatedMedia.spec.ts \
  frontend-admin/src/design-system/components/AuthenticatedMedia.vue \
  docs/tasks/TASK-223-authenticated-media-race.md \
  docs/tasks/TASK-224-media-retry.md

commit "test(admin): repair a suite that had been red since 2026-08-17 (TASK-225)

npm run test:unit in frontend-admin reported 56 failed / 50 passed. None
of it was new: SalesTeamView.vue last changed 2026-08-20 while its spec
last changed 2026-08-17, and the same gap holds for the other four. The
suite has been red for three days and nobody ran it.

That matters more than the number. A red suite hides its own regressions
— it is the same reason TASK-222's 500 sat in
RoleGateCharacterizationTest for weeks marked 'Recorded, NOT fixed'.

ONE cause took out five files. ADR-038/TASK-209 gave the app a global
active-company store, and every scopable view now calls
useActiveCompanyStore() in setup(), so mounting any of them without a
Pinia throws. Fixed in one place — vitest.setup.ts — rather than five
copies of the same four lines, because 'a mounted view has a Pinia' is a
property of the app, not of each spec; pasting it five times leaves the
sixth view to rediscover this the same way. A FRESH Pinia per test, never
one shared instance: a company selected in one test leaking into the next
is an order-dependent failure that costs an afternoon.

The last remaining failure was a different kind, and worth reading. The
test asserted that the video / team-visibility / commission-split cards
still live on ThemeSettingsView — but TASK-202 moved all three onto their
own routes on 2026-08-17. The assertion had been false since that day and
the red suite hid it. Replaced rather than deleted, asserting the
opposite: re-absorbing those cards would undo TASK-202 silently, so 'they
are not here' is still worth pinning.

frontend-admin: 9 files, 106 passed. frontend: 13 files, 132 passed
(already green, including TASK-224's new spec).

Verified by installing node_modules fresh on Linux in the sandbox and
running both suites — the same 56/50 reproduced first, then went green.
This project's macOS node_modules cannot run here (rolldown native
binding), which is why earlier frontend work could only be type-checked." \
  frontend-admin/vitest.setup.ts \
  frontend-admin/vitest.config.ts \
  frontend-admin/src/views/__tests__/ThemeSettingsView.spec.ts \
  docs/tasks/TASK-225-admin-test-suite-repair.md

commit "fix(uploads): honour the selected company's video size ceiling (TASK-226)

human set 300 MB for a company in production, saved it, and the upload
still came back 'ไฟล์ใหญ่เกินขนาดที่บริษัทกำหนด (200 MB)'. 200 is the
platform default, not the value that was saved — the server never read
that company's row at all.

TASK-222 is why. It stopped /uploads/init from throwing a TypeError on a
Super Admin by making forCompany() accept null, and null correctly
returns the platform default. But init() still passed \$user->company_id
straight through, and a Super Admin's company_id is NULL by design — so
he got the default EVERY time, no matter which company the header picker
was showing. Invisible to a Company Admin, whose company_id is never
null. Exactly the account human was using.

The fix sends the active company with /uploads/init. Three deliberate
calls: a Company Admin's supplied company_id is IGNORED, not rejected —
trusting it would let one tenant borrow another's larger cap (BR-6), and
403 punishes a field that changes nothing. 'sometimes' not 'required' —
'ทุกบริษัท' is a real state of that picker and refusing to upload in it
is a worse answer than the platform default. exists:companies,id so a
typo cannot silently mean 'no company'. The resolved id feeds BOTH the
ceiling and the stored row, so a file's owner and its cap can never come
from different companies.

utils/activeCompanyStorage.ts exists because api/client.ts cannot import
the store — the store imports the client to fetch the company list, and
that cycle resolves to undefined at module-evaluation time and fails only
in a production build. The leaf imports nothing of the app. It has a
reader and NO writer on purpose: the store keeps sole ownership of the
value and every rule around it, this file owns only the key.

3 tests: the named company's 300 MB ceiling (fails on the old code with
the 200 MB message), 'ทุกบริษัท' still uploading, and a Company Admin's
company_id being ignored. backend 1665 passed / 6231 assertions;
frontend-admin 106 passed, vue-tsc and eslint clean." \
  backend/app/Http/Controllers/Api/V1/ChunkedUploadController.php \
  backend/tests/Feature/Catalog/ChunkedUploadTest.php \
  frontend-admin/src/utils/activeCompanyStorage.ts \
  frontend-admin/src/stores/activeCompany.ts \
  frontend-admin/src/api/client.ts \
  docs/tasks/TASK-226-video-upload-ceiling.md

commit "fix(storage): route every saved preference through safeStorage (TASK-227)

human's npm run test:unit: 42 failed, 42 passed. Same commit passed
106/106 in the sandbox — same vitest 4.1.10, same jsdom 29.1.1, same
config, identical line numbers in the spec files. Node 22 and Node 24
both passed here too. The code was byte-identical; only the environment
differed.

Proved it rather than guessed: replacing localStorage with a bare {}
before the suite ran reproduced 42 failed across exactly 3 files, with
both of human's messages verbatim. So on that machine localStorage exists
as an object carrying none of the Storage methods. It looked scattered
only because every file that touches Storage died and every file that
does not passed.

This project already answered this. safeStorage.js was written on
2026-08-12 for a localStorage 'whose getItem was not a function', and its
docblock names 'a test environment can supply a partial object' outright.
ADR-003 keeps a copy in frontend-admin — but only useI18n and useFontSize
ever used it. The lesson was applied to half the codebase.

I first wrote an in-memory Storage into vitest.setup.ts and threw it
away. It masks the property the project deliberately relies on to catch
this class of bug, and — the part that matters — it fixes nothing real.
AcademyManagementView reads storage during setup(), so Safari private
mode, a sandboxed iframe, or site data switched off WHITE-SCREENS the
Academy page. CommissionPlansView has the same read and no spec at all.
activeCompany's setItem has no guard, so choosing a company throws. Those
red tests were reporting a production bug; making them green without
fixing it would have been silencing them.

HeroHeader in BOTH apps had a hand-rolled try/catch. It guards a storage
that THROWS, not one that exists without working methods — which is the
case that actually occurs. That is the same wrong guard safeStorage's
docblock calls out, so both copies now go through it. seenAnnouncements
is left alone: its catch also covers a JSON.parse that can fail
independently, and it reads lazily.

The two specs calling localStorage.clear() directly lost that line, with
the same note the agent portal's PipelineBoardConfirmOrder.spec.ts
already carries. Neither suite writes to storage.

Also fixes TS2578 in both copies of useAuthenticatedMedia.spec.ts, mine
from TASK-223/224. vue-tsc --noEmit -p tsconfig.app.json does not see
test files; npm run type-check (vue-tsc --build) does, and I had only run
the first. TypeScript was reporting the @ts-expect-error as UNUSED — i.e.
the document fallback it guarded could never be reached — so the fallback
went, not just the directive.

frontend-admin 106 passed, frontend 132 passed — both normally AND with
the broken-Storage environment simulated. vue-tsc --build and eslint
clean in both apps.

NOT fixed: why macOS hands jsdom a method-less localStorage when every
package version matches. Proved what happens and made the code tolerate
it; did not prove why." \
  frontend-admin/src/utils/activeCompanyStorage.ts \
  frontend-admin/src/stores/activeCompany.ts \
  frontend-admin/src/views/AcademyManagementView.vue \
  frontend-admin/src/views/CommissionPlansView.vue \
  frontend-admin/src/design-system/components/HeroHeader.vue \
  frontend-admin/src/views/__tests__/ReferralPipelineManagementView.spec.ts \
  frontend/src/design-system/components/HeroHeader.vue \
  docs/tasks/TASK-227-safe-storage-admin.md

commit "fix(announcements): show the popup image whole instead of cropping it (TASK-228)

human sent a screenshot of the announcement popup with the bottom third
of the banner missing — the 'โซนดีล GENESENN' strip, which is the part
the announcement existed to show.

One line: the image was 'h-56 sm:h-64 object-cover' (h-72 on
full_screen). A fixed-height box that object-cover then filled by
CROPPING the overflow, so every image was cut to the same 224/256/288px
regardless of its real shape.

Now w-full + h-auto with no object-* at all, so the browser derives the
height from the intrinsic ratio and nothing can be cropped. NOT
object-contain: contain keeps the fixed box and letterboxes inside it,
which shows the whole image but leaves dead bars above and below.
Dropping the height gives neither crop nor bars.

Left UNCAPPED by human's explicit choice when asked. Overflow is still
impossible because the CARD already carries max-h-[85vh]/max-h-[92vh]
with overflow-y-auto — a tall image scrolls the content, it cannot grow
the modal past the viewport. Accepted trade-off: with a very tall
portrait image the title and body sit below the fold.

Popup only, also human's choice. AnnouncementBanner.vue keeps
object-cover: those are preview cards in a grid where equal heights are
the point.

Adds the component's FIRST spec. Four display styles, three callers and
no test at all is how object-cover survived from TASK-075 until a human
had to screenshot it. The 12 tests assert the rule rather than the
utility strings — no object-cover AND no object-contain, and h-auto as
the only permitted h-* utility so a 'sm:h-64' cannot creep back — so
restyling stays free as long as the image still shows in full. Run
against the old markup they fail 8 of 12; against the fix, 12 pass.

frontend 144 passed (14 files), vue-tsc --build and eslint clean.

NOT fixed: one reflow when the image loads, since its height is not known
in advance and the API does not send dimensions. bg-surface-chip keeps
that moment from flashing, but does not remove the reflow." \
  frontend/src/design-system/components/AnnouncementModal.vue \
  frontend/src/design-system/components/__tests__/AnnouncementModal.spec.ts \
  docs/tasks/TASK-228-announcement-image-full.md

commit "fix(deploy): stop index.html being served from cache (TASK-229)

human: video uploads still capped at 200 MB hours after TASK-226 went
live. The code was right and it WAS deployed. The browser was running the
pre-deploy bundle.

Checked through the live admin session rather than guessing. localStorage
held company 1, /me said super_admin, and
/video-processing-settings?company_id=1 returned max_upload_mb 300 — so
every server-side layer was correct. The chunk carrying /uploads/init in
the RUNNING page (Icon-MiqkDfgl.js) had size_bytes but no company_id. The
chunk on the server (Icon-CoUDrwZ_.js) had both. index.html on the server
pointed at index-Ds3ax1IP.js; the page had loaded index-8p9WGQoD.js.

Root cause: index.html is served with NO Cache-Control at all, while
assets get public, max-age=604800. Vite fingerprints everything under
assets/, so long caching there is correct — but index.html is the only
file that says WHICH hash is current, and with no header the browser
falls back to heuristic caching off Last-Modified and picks a lifetime
itself. Deploys land, and users keep loading last week's JS until
something makes them hard-refresh. This did not just hide TASK-226; it
has been hiding every frontend deploy.

no-cache, must-revalidate on *.html in both apps' public/.htaccess.
no-cache does not mean do not store — it means revalidate first, and with
Last-Modified already sent an unchanged page costs a 304. The assets
policy is deliberately untouched: those are content-hashed and caching
them a week is the right answer.

public/ specifically, not placed on the server by hand: deploy.sh rsyncs
with --delete, so anything not shipped from public/ is removed on the
next deploy. Vite copies public/ into dist/ verbatim, so this now ships
with every npm run deploy.

The doc records the 10-second check (compare the script tags the page
loaded against the ones index.html currently names) that should be run
FIRST next time production looks unchanged after a deploy — I reached for
it last, after two wrong hypotheses.

Verified live: forced a cache-bypassing reload and the tab now runs
index-Ds3ax1IP.js. The 200 MB ceiling itself still needs a real >200 MB
upload to call proven." \
  frontend/public/.htaccess \
  frontend-admin/public/.htaccess \
  docs/tasks/TASK-229-index-html-cache.md

commit "feat(academy): lesson แก้ไข/ลบ on the outline row, behind a confirm (TASK-230)

human asked how to delete or edit a lesson. The answer was 'select it
first, then look at the card header on the right' — which is why they
could not find it. They asked for the pair to move onto the end of each
row in the course outline, and for a mockup before any code. Mockup
approved with three choices: reveal on hover/selection rather than
always, drop the card copy, and add the missing delete confirmation in
the same change.

ONE DELIBERATE DEPARTURE from 'drop the card copy': it is dropped only in
the WIDE layout. Below 1024px the outline panel does not exist at all
(v-if=isWideLayout), so removing both copies would leave a tablet unable
to edit or delete a lesson. The two sets are mutually exclusive by
viewport, so what human actually asked for — never two delete buttons on
one screen — still holds exactly.

Three details in the row markup that are easy to lose later.
group-focus-within alongside group-hover: without it the buttons are
Tab-focusable but invisible, which is worse than absent. .stop on both:
the row is itself a click target that selects, so deleting would
otherwise also select the row it just removed — nothing throws, the
inspector just renders a lesson that is gone. And the SELECTED row shows
them permanently, because a touch device has no hover and that is its
only route.

The confirmation is not a nice-to-have bundled in. deleteLesson fired
straight off the click, taking the lesson and every learner's progress
with it, while deleting a SECTION — strictly larger blast radius — has
been confirmed since TASK-066. That gap survived only because the button
was buried behind a selection. Putting it on every row removes exactly
that accidental protection, so shipping the easier button without the
dialog would be shipping the regression first. The body is computed from
the lesson: a published lesson with a 12-question quiz and an unseen
draft are not the same decision, and a warning that cannot tell them
apart trains admins to click through it.

11 tests. Visibility is asserted through the class contract, not
exists(), so a later 'tidy the outline row' pass that drops the
group-hover utilities fails instead of silently shipping unclickable
buttons. Both widths are covered — the same trap TASK-188's tests already
document. Run against the old markup they fail 10 of 26; against this,
26 pass. frontend-admin 117 passed, vue-tsc --build and eslint clean.

NOT done: never rendered on a real screen — hover feel is something you
have to look at. The dialog also does not name how many learners have
progress; that needs an extra GET per delete, so it says only what is
free to know." \
  frontend-admin/src/views/AcademyManagementView.vue \
  frontend-admin/src/views/__tests__/AcademyManagementView.spec.ts \
  docs/tasks/TASK-230-lesson-row-actions.md

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
