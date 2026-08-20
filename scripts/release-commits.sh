#!/usr/bin/env bash
#
# scripts/release-commits.sh — split the 2026-08-20 working tree into
# reviewable commits on a release branch.
#
# WHY THIS IS A SCRIPT AND NOT ALREADY COMMITTED
# ag-lead could not run `git add` from the agent sandbox: that mount
# forbids unlink(), and git must delete .git/index.lock after every write.
# Everything below is exactly what would have been run.
#
# Run it from the repo root, in YOUR terminal:
#     bash scripts/release-commits.sh
#
# It is safe to re-run: each commit is skipped if it has nothing staged.
#
# HONEST LIMITATION — three files carry changes from more than one task
# (git commits whole files, and this working tree has no per-task history):
#   * frontend-admin/src/views/ProductCatalogView.vue  (TASK-202..205 + 213)
#   * frontend-admin/src/views/CommissionPlansView.vue (TASK-213 + 214 + 216)
#   * backend/routes/api.php                           (every task that added a route)
# Each is committed with its dominant task; routes/api.php is deliberately
# held to the LAST commit so every earlier commit's classes exist before
# anything routes to them.

set -euo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# ag-lead's sandbox left a stale lock behind; harmless to remove.
rm -f .git/index.lock
rm -rf _to_delete

BRANCH=release/2026-08-20-commission
git rev-parse --verify "$BRANCH" >/dev/null 2>&1 || git checkout -b "$BRANCH"
git checkout "$BRANCH"

commit() {  # commit <subject> <body> <paths...>
  local subject="$1" body="$2"; shift 2
  git add -- "$@" 2>/dev/null || true
  if git diff --cached --quiet; then
    echo "  (nothing staged — skipped) $subject"
    return
  fi
  git commit -q -m "$subject" -m "$body"
  echo "✓ $subject"
}

# ── 0. keep the scratch bucket out of git ───────────────────────────────
if ! grep -q '_to_delete' .gitignore 2>/dev/null; then
  printf '\n# Scratch bucket for files queued for manual deletion.\n_to_delete/\n' >> .gitignore
fi
commit "chore: gitignore the _to_delete scratch bucket" \
"device_bash cannot rm inside a mounted folder, so files queued for deletion
are moved to _to_delete/ instead. Local workspace artefact, never committed." \
  .gitignore

# ── 1. ADR-036 shared catalog + brand/category UX ───────────────────────
commit "feat(catalog): shared cross-company product catalog + brand/category UX (ADR-036, TASK-202..205)" \
"One product sold by several companies, without duplicating the taxonomy:
catalog_brands / catalog_categories / product_catalog_items (+media, +specs)
and a link from products to a catalog item.

Admin UX from the same batch rides along in ProductCatalogView.vue:
name-first grouping, the tick-box company picker, brand logo upload, and
the modal conversion of the brand/category drawer.

ProductCatalogView.vue also carries TASK-213 phase 3 (the two commission
tabs removed in favour of แผนคอมมิชชั่น) — one file, two tasks." \
  backend/database/migrations/2026_08_18_100000_make_cert_tier_id_nullable_on_commission_rules_table.php \
  backend/database/migrations/2026_08_18_1200*.php \
  backend/database/migrations/2026_08_18_120600_make_brand_category_name_nullable_on_products_table.php \
  backend/app/Models/CatalogBrand.php backend/app/Models/CatalogCategory.php \
  backend/app/Models/ProductCatalogItem.php backend/app/Models/ProductCatalogMedia.php \
  backend/app/Models/ProductCatalogSpec.php backend/app/Models/Product.php \
  backend/app/Http/Controllers/Api/V1/CatalogBrandController.php \
  backend/app/Http/Controllers/Api/V1/CatalogCategoryController.php \
  backend/app/Http/Controllers/Api/V1/ProductCatalogItemController.php \
  backend/app/Http/Controllers/Api/V1/ProductCatalogLinkController.php \
  backend/app/Http/Controllers/Api/V1/BrandController.php \
  backend/app/Http/Controllers/Api/V1/ProductController.php \
  backend/app/Http/Controllers/Api/V1/ProductCategoryController.php \
  backend/app/Http/Requests/Catalog \
  backend/app/Http/Resources/CatalogBrandResource.php backend/app/Http/Resources/CatalogCategoryResource.php \
  backend/app/Http/Resources/ProductCatalogItemResource.php backend/app/Http/Resources/ProductCatalogMediaResource.php \
  backend/app/Http/Resources/ProductCatalogSpecResource.php backend/app/Http/Resources/BrandResource.php \
  backend/app/Http/Resources/ProductCategoryResource.php backend/app/Http/Resources/ProductResource.php \
  backend/app/Policies/CatalogBrandPolicy.php backend/app/Policies/CatalogCategoryPolicy.php \
  backend/app/Policies/ProductCatalogItemPolicy.php backend/app/Policies/ProductPolicy.php \
  backend/app/Services/Catalog/BrandService.php backend/app/Services/Catalog/CatalogBrandService.php \
  backend/app/Services/Catalog/CatalogCategoryService.php backend/app/Services/Catalog/ProductCatalogItemService.php \
  backend/app/Services/Catalog/ProductService.php \
  backend/database/factories/CatalogBrandFactory.php backend/database/factories/CatalogCategoryFactory.php \
  backend/database/factories/ProductCatalogItemFactory.php \
  backend/tests/Feature/Catalog \
  frontend-admin/src/views/CatalogManagementView.vue \
  frontend-admin/src/views/ProductCatalogView.vue \
  frontend-admin/src/views/ProductEditView.vue \
  frontend-admin/src/design-system/components/CompanyMultiSelect.vue \
  docs/adr/ADR-036-shared-cross-company-product-catalog.md \
  docs/tasks/TASK-202-brand-category-company-clarity.md \
  docs/tasks/TASK-203-multi-company-create-brand-category.md \
  docs/tasks/TASK-204-name-first-brand-category-list.md \
  docs/tasks/TASK-205-brand-logo-upload.md \
  docs/qa/UAT-014-brand-category-catalog-ux.md

# ── 2. TASK-206 — the silent money bug in catalog linking ───────────────
commit "fix(commission): keep a product's own brand/category when linking to the catalog (TASK-206)" \
"Linking a product to a catalog item used to NULL its brand_id/category_id.
CommissionService::resolveCommissionRule() walks product > category >
company, so a null category silently skipped a rung and paid the wrong
rate into an immutable ledger row (BR-4) with no error anywhere.

link() now mirrors the catalog taxonomy into the product's own company.
catalog:backfill-linked-taxonomy repairs rows written before the fix —
idempotent, fills nulls only." \
  backend/app/Console/Commands/BackfillCatalogLinkedTaxonomyCommand.php \
  backend/app/Services/Catalog/ProductCatalogLinkService.php \
  docs/tasks/TASK-206-keep-brand-category-on-catalog-link.md

# ── 3. ADR-038 — one company scope for the whole Admin app ──────────────
commit "feat(admin): one global company scope for Super Admin (ADR-038, TASK-208/209)" \
"Ten screens each had their own company <select>, none shared state, none
survived a route change — so a Super Admin could edit a product without
knowing whose it was. The picker now lives once in AdminNavigation and
every screen reads the activeCompany store.

Server-side, CompanyScopeFilter applies the scope on 21 list endpoints.
It can only ever NARROW: TenantScope has already answered for anyone who
is not a Super Admin, so a hand-written ?company_id cannot widen a
Company Admin's view (BR-6). Pinned by CompanyScopeFilterTest.

PDPA client screens refuse the 'all companies' mode outright — health
data is never shown company-wide (human ruling)." \
  backend/app/Support/CompanyScopeFilter.php \
  backend/tests/Feature/Platform/CompanyScopeFilterTest.php \
  backend/app/Http/Controllers/Api/V1/AgentApprovalController.php \
  backend/app/Http/Controllers/Api/V1/AgentInviteLinkController.php \
  backend/app/Http/Controllers/Api/V1/AgentPromotionController.php \
  backend/app/Http/Controllers/Api/V1/AnnouncementController.php \
  backend/app/Http/Controllers/Api/V1/CertTierController.php \
  backend/app/Http/Controllers/Api/V1/ClientController.php \
  backend/app/Http/Controllers/Api/V1/CommissionLedgerController.php \
  backend/app/Http/Controllers/Api/V1/ExamController.php \
  backend/app/Http/Controllers/Api/V1/ModuleController.php \
  backend/app/Http/Controllers/Api/V1/OrderController.php \
  backend/app/Http/Controllers/Api/V1/ProductPricePromotionController.php \
  backend/app/Http/Controllers/Api/V1/ReferralController.php \
  backend/app/Http/Controllers/Api/V1/RewardItemController.php \
  backend/app/Http/Controllers/Api/V1/RewardRedemptionController.php \
  backend/app/Http/Controllers/Api/V1/StorefrontBannerController.php \
  backend/app/Http/Controllers/Api/V1/UserBadgeController.php \
  backend/app/Http/Controllers/Api/V1/UserCertificationController.php \
  backend/app/Http/Controllers/Api/V1/UserController.php \
  frontend-admin/src/stores/activeCompany.ts \
  frontend-admin/src/api/client.ts frontend-admin/src/router/index.ts \
  frontend-admin/src/design-system/components/CompanySwitcher.vue \
  frontend-admin/src/design-system/components/CompanyScopeNotice.vue \
  frontend-admin/src/design-system/components/PlatformScopeBadge.vue \
  frontend-admin/src/design-system/components/AdminNavigation.vue \
  frontend-admin/src/views/AcademyManagementView.vue \
  frontend-admin/src/views/AgentApprovalsView.vue \
  frontend-admin/src/views/AgentCommissionSummaryView.vue \
  frontend-admin/src/views/AgentInviteLinksView.vue \
  frontend-admin/src/views/AnnouncementsView.vue \
  frontend-admin/src/views/ClientManagementView.vue \
  frontend-admin/src/views/CommissionSplitSettingsView.vue \
  frontend-admin/src/views/CompanyManagementView.vue \
  frontend-admin/src/views/GamificationConfigView.vue \
  frontend-admin/src/views/MailSettingsView.vue \
  frontend-admin/src/views/PolicyReportView.vue \
  frontend-admin/src/views/ProductPerformanceView.vue \
  frontend-admin/src/views/ReferralPipelineManagementView.vue \
  frontend-admin/src/views/RewardCenterView.vue \
  frontend-admin/src/views/TeamVisibilitySettingsView.vue \
  frontend-admin/src/views/ThemeSettingsView.vue \
  frontend-admin/src/views/VideoSettingsView.vue \
  frontend-admin/src/views/agentEdit.ts \
  docs/adr/ADR-038-global-active-company-scope.md \
  docs/tasks/TASK-208-global-company-switcher.md \
  docs/tasks/TASK-209-company-scope-coverage-plan.md \
  docs/qa/UAT-015-company-scope-all-routes.md

# ── 4. TASK-210 ─────────────────────────────────────────────────────────
commit "feat(admin): confirm a successful save after the agent-edit modal closes (TASK-210)" \
"submitEdit() already closed the modal on a 2xx, which from the admin's
side is indistinguishable from dismissing it. The inline 'saved' line
could not fill the gap — it lives inside the modal that just disappeared.

The saved event now carries its own sentence and the host renders it in
SuccessDialog. Only the writes that CLOSE the modal carry one; granting a
tier leaves the modal open and already reports inline." \
  frontend-admin/src/design-system/components/SuccessDialog.vue \
  frontend-admin/src/views/AgentEditModal.vue \
  frontend-admin/src/views/AgentRosterView.vue \
  frontend-admin/src/views/SalesTeamView.vue \
  docs/tasks/TASK-210-save-success-dialog.md

# ── 5. TASK-211 ─────────────────────────────────────────────────────────
commit "fix(agent): branch is optional, and the save button says what is missing (TASK-211)" \
"Reported as 'cannot save'. Nothing was broken: สาขา was empty and both the
handler and the button's disabled binding tested the same condition, so
pressing บันทึก did nothing, sent nothing and said nothing. Confirmed from
the reporter's DevTools capture: no POST was ever made.

The press now reaches the handler, which names the missing field. Per the
human's follow-up ruling สาขา became optional server-side too, so the form
and StoreReferralRequest agree.

Consequence handled in the same change: branch IS NULL was the de facto
'sold through a shared link' marker, so the four labels that inferred that
now read ไม่ระบุสาขา instead of claiming an origin they cannot know." \
  backend/app/Http/Requests/Referral/StoreReferralRequest.php \
  backend/tests/Feature/Referral/ReferralTest.php \
  frontend/src/views/ClientsView.vue \
  frontend/src/design-system/components/PipelineBoard.vue \
  docs/tasks/TASK-211-referral-form-silent-disable.md

# ── 6. TASK-212 ─────────────────────────────────────────────────────────
commit "feat(share): email a shared link through the platform SMTP instead of mailto: (TASK-212)" \
"mailto: never did what the button implied on a phone: it hands off to
whatever mail client is installed — or to nothing at all, silently — and
the message leaves from the agent's personal address with no record.

POST /share-emails takes {type, id, email}, never a URL. An endpoint that
mailed a caller-supplied URL from this platform's From: address would be
an authenticated open relay; the server resolves the target, checks the
EXISTING policy, and builds the URL itself.

Fails closed when platform mail is off: with no settings row Laravel is
still pointed at the log mailer, so a 'success' would mean the message was
written to a file while the agent was told the customer had it." \
  backend/app/Enums/ShareLinkType.php backend/app/Mail/ShareLinkMail.php \
  backend/app/Services/Share backend/app/Http/Requests/Share \
  backend/app/Http/Controllers/Api/V1/ShareLinkEmailController.php \
  backend/app/Http/Resources/OrderResource.php \
  backend/tests/Feature/Share \
  frontend/src/design-system/components/ShareLinkModal.vue \
  frontend/src/composables/useReferralOrders.ts \
  frontend/src/views/OrdersView.vue frontend/src/views/MyTeamView.vue \
  frontend/src/views/ProductBrowseView.vue \
  docs/tasks/TASK-212-share-link-email-through-platform.md

# ── 7. TASK-213 (docs; the code rides in commits 1 and 10) ──────────────
commit "docs(commission): consolidation plan and overlap-detection notes (TASK-213)" \
"Audit of every place a commission rate can be set — 13 surfaces across 5
screens, one table writable from four forms with unequal powers, and 13
code paths that pay nobody in silence.

Phase 1-3 shipped: a readiness panel that names those silent paths before
a deal hits one, the team-leader rate moved out of the product catalogue,
and the duplicate rate forms retired. r2 adds detection for rules that
overlap in the same scope — found in live data on the reporter's machine." \
  docs/tasks/TASK-213-commission-config-consolidation-p1-3.md \
  docs/tasks/TASK-213-r2-overlap-detection.md \
  docs/tasks/PLAN-commission-config-consolidation.md \
  docs/tasks/ui-proposal-commission.html

# ── 8. TASK-214 — THE ONE THAT MOVES MONEY ──────────────────────────────
commit "feat(commission)!: scope the team-leader rate by product/category and drop its cert-tier key (TASK-214)" \
"Two human rulings, 2026-08-19: the leader rate no longer varies by the
manager's cert tier, and it resolves in exactly the order the selling
agent's rate uses — product > category > company.

manager_cert_tier_id is kept and simply ignored, the same treatment
ADR-035 gave commission_rules.cert_tier_id: dropping it would destroy the
only record of what a legacy row meant, which is what a human needs to
decide which of several per-tier rows survives.

BREAKING for existing data. Rows that differed only by cert tier were
legal before and become an ambiguous overlap the moment resolution stops
reading the tier — the payout would be whichever row the database returns
first, into an immutable ledger. Run IMMEDIATELY after migrating:

    php artisan commission:collapse-override-tiers --dry-run
    php artisan commission:collapse-override-tiers

It asks rather than choosing: 'keep the highest' is as arbitrary as 'keep
the lowest' and both would be an invented number wearing the costume of a
migration (BR-7).

Eligibility is unchanged: a manager must still hold a passed cert tier to
be paid at all. That is a gate, not a rate key — ADR-035's own framing.

Also fixes silent truncation: both rate endpoints paginated at 15 while
the only client reads `data` and nothing else, so the sixteenth rate
vanished from the screen while still being the one that pays." \
  backend/database/migrations/2026_09_03_090000_add_scope_to_commission_override_rules_table.php \
  backend/app/Models/CommissionOverrideRule.php \
  backend/app/Services/Commission/CommissionService.php \
  backend/app/Services/Commission/CommissionOverrideRuleService.php \
  backend/app/Http/Requests/Commission \
  backend/app/Http/Resources/CommissionOverrideRuleResource.php \
  backend/app/Http/Controllers/Api/V1/CommissionOverrideRuleController.php \
  backend/app/Http/Controllers/Api/V1/CommissionRuleController.php \
  backend/app/Console/Commands/CollapseOverrideTiersCommand.php \
  backend/tests/Feature/Commission/CommissionOverrideRuleTest.php \
  backend/tests/Feature/Commission/CommissionOverrideCalculationTest.php \
  backend/tests/Feature/Commission/AffiliateOverrideCommissionTest.php \
  backend/tests/Feature/Commission/CollapseOverrideTiersCommandTest.php \
  docs/tasks/TASK-214-leader-rate-scope.md

# ── 9. TASK-215 ─────────────────────────────────────────────────────────
commit "fix(commission): show what kind of money each ledger row is, and find sales that paid nobody (TASK-215)" \
"Found during UAT-016. One closed sale wrote three rows — the seller's
3.00%, the leader's 2.50% override and a 10% campaign bonus — and the
ledger rendered all three identically: same client, same product, same
date. The honest first reading was 'this sale paid the same agent twice'.
It had not, but an accountant reconciling a payout run would reach the
same wrong conclusion. earned_via was being sent and thrown away.

commission:audit-unpaid-closures finds closed sales that never produced a
commission row for the SELLING agent — a promotion bonus on such a sale
looks like money moved, and it did, to the wrong question. Three real
ones were found on the reporter's machine. Read-only: whether to
compensate, and at what rate, is a business decision, not a repair script.

Also: the two overlap-guard messages were English in a Thai-only UI." \
  backend/app/Console/Commands/AuditUnpaidClosuresCommand.php \
  backend/tests/Feature/Commission/AuditUnpaidClosuresCommandTest.php \
  backend/app/Services/Catalog/CommissionRuleService.php \
  frontend-admin/src/views/CommissionManagementView.vue \
  docs/qa/UAT-016-simple-commission-end-to-end.md \
  docs/qa/UAT-016-RESULT-2026-08-19.md

# ── 10. TASK-216 ────────────────────────────────────────────────────────
commit "fix(admin): commission forms become real modals that name what they edit (TASK-216)" \
"'I cannot tell which one I am editing.' The forms opened inline at the
top of the page while the row whose แก้ไข was clicked sat further down and
often off-screen — and the agent-rate form had no heading at all.

Now a 70vw x 60vh modal over a dark overlay, with the action as an eyebrow
and the target name as an h1, read from the FORM so it is useful while
creating too. Header pinned, body scrolls, footer pinned — sticky bottom-0
was tried first and does not work, sticky only pins once content overflows.

CommissionPlansView.vue also carries TASK-213 and TASK-214's UI." \
  frontend-admin/src/views/CommissionPlansView.vue \
  frontend-admin/src/views/AgentPromotionsView.vue \
  docs/tasks/TASK-216-form-identity-headers.md \
  docs/tasks/TASK-216-r2-real-modals.md \
  docs/tasks/PLAN-product-commission-one-stop.md

# ── 11. routes + the remainder ──────────────────────────────────────────
commit "chore: register this batch's routes, plus ADR-035 follow-ups and release notes" \
"routes/api.php is held to last on purpose: it is touched by nearly every
task above, so wiring it only once every controller exists keeps each
earlier commit self-consistent.

Sweeps in the remaining ADR-035 (flat-rate Unilevel) follow-ups and the
release plan for this batch." \
  backend/routes/api.php \
  docs/adr/ADR-035-unilevel-flat-rate-drop-cert-tier.md \
  docs/RELEASE-PLAN-2026-08-20.md \
  backend/app/Console/Commands/DispatchDueRenewalCommissions.php \
  backend/tests/Feature/Commission/CommissionCalculationTest.php \
  backend/tests/Feature/Sales/SalesTeamOverviewTest.php \
  scripts/release-commits.sh

# ── 12. anything missed ─────────────────────────────────────────────────
if [ -n "$(git status --porcelain)" ]; then
  echo
  echo "Files not covered by the groups above — sweeping into one commit:"
  git status --porcelain
  git add -A
  git commit -q -m "chore: remaining files from the 2026-08-20 batch" \
    -m "Not assigned to a task group by scripts/release-commits.sh — review before merging."
fi

echo
echo "── done ──"
git log --oneline origin/main..HEAD 2>/dev/null || git log --oneline -13
echo
echo "Working tree clean: $([ -z "$(git status --porcelain)" ] && echo yes || echo NO)"
echo
echo "Next:"
echo "  git push -u origin $BRANCH     # then open a PR and merge into main"
