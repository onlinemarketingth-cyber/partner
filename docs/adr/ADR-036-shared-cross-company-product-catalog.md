# ADR-036: Shared Cross-Company Product Catalog (Same Product, Multiple Companies, Independent Price/Commission)

- **Date:** 2026-08-18
- **Status:** Accepted — scope locked in by the human's explicit answers below. Every price/commission value inside this scope remains **undecided (BR-7)** and is flagged, not invented.
- **Author:** ag-lead
- **Depends on:** Section 5 (Multi-Tenancy & Data Isolation, BR-6 — highest priority), ADR-011 (unified commission architecture — `commission_rules`/`products` relationship unchanged by this ADR), ADR-022 (product cover/detail media), TASK-097 (product_media.purpose)

## Context

The human noticed the Admin product catalog can show two products with the identical name under different companies (e.g. Thai Life and Genesenn both listing "Vital Blueprint Mini + BALANZ V5 Health Tracker"). Today these are two fully independent `products` rows — no schema link between them — because `products.company_id` is a hard 1-row-1-company design (confirmed via `2026_07_09_100040_create_products_table.php`: no unique constraint on `name`, no cross-company FK anywhere).

The human asked ag-lead to design the **root fix**: the same real-world product should be representable once, sellable through multiple companies, with **price and commission set independently per company**.

### Locked-in decisions (human's answers, verbatim intent)

| Question | Decision |
|---|---|
| Does "สาขา" mean separate companies (tenants), or a new "branch" sub-entity inside one company? | **Separate companies (tenants)** — the Thai Life / Genesenn case is exactly it. |
| Which parts of "the same product" must stay identical across companies, and which must be independently settable? | **Identical:** name, description, spec, media, **brand, category** (§2). **Independent per company:** price, commission (§3). |
| Who may edit the shared content (name/media/spec/description/brand/category)? | **Super Admin only.** |
| Who sets the per-company price and commission for a shared product? | **Super Admin only** — not self-service by Company Admin. Super Admin picks a company and sets that company's price/commission for the shared product; Company Admin only ever sees a read-only result. |

This is a genuine reversal of the platform's current assumption that "one company owns everything about its own product row." It does **not** touch BR-6 for any of the 15 existing tables that already FK into `products.id` (referrals, commission_rules, commission_ledger, product_media, product_specs, product_sales_materials, storefront_banners, product_share_links, affiliate_links, agent_promotions, product_price_promotions, product_recommendation_pins, orders, modules) — see §4.

## 1. Design Principle: Additive, Opt-In, Zero Blast Radius on Existing Data

The existing `products` table (and everything that FKs into it) is **not restructured**. A new, unscoped ("global") catalog layer sits alongside it; a product opts in by linking to it. Every product row that exists today keeps `catalog_item_id = null` forever unless a human explicitly links it — no automatic name-matching, no retroactive merge (two products sharing a name today are not assumed to be the same real product; merging is a per-pair human decision, never inferred).

## 2. New Schema — the Shared ("Global") Layer

Three new tables, **none carry `company_id`, none use `TenantScope`** — by design, they must be readable across every company:

- **`catalog_brands`** (`id`, `name`, `logo_path`, `is_active`, timestamps, soft-deletes) — the shared brand standard. Mirrors `brands` but global.
- **`catalog_categories`** (`id`, `name`, `icon`, `sort_order`, `is_active`, timestamps, soft-deletes) — the shared category standard. Mirrors `product_categories` but global.
- **`product_catalog_items`** (`id`, `catalog_brand_id` FK, `catalog_category_id` FK, `name`, `description`, `spec_description`, `is_active`, timestamps, soft-deletes) — the shared product identity. Owns the shared `product_media`/`product_specs` rows going forward (those tables' `product_id`-style FK gains a sibling `catalog_item_id`, mutually exclusive with `product_id` — a media/spec row belongs to exactly one owner, never both).

**Write access to all three is Super-Admin-only** (per the human's answer) — enforced by dedicated Policies, not by omission. No Form Request, Service, or Controller for these three tables accepts a Company Admin actor.

## 3. Existing Schema — the Per-Company Layer (Unchanged Shape, One New Column)

`products` gets exactly one new nullable column: **`catalog_item_id`** (FK to `product_catalog_items`, nullable). Everything else about the row is unchanged:

- **`catalog_item_id = null`** (every existing row, and any future row an admin chooses not to link): today's exact behavior. `name`, `description`, `spec_description`, `brand_id`, `category_id`, its own `product_media`/`product_specs` rows — all local to this one company, Company Admin has full CRUD, nothing about this ADR applies.
- **`catalog_item_id` set:** the row's own `name`/`description`/`spec_description`/`brand_id`/`category_id` become **unused** — `ProductResource` resolves these fields from `product_catalog_items` (and its `catalog_brand`/`catalog_category`) instead, the same "effective_" resolution pattern already used for `effective_plan_type`/`effective_affiliate_override_mode`/`effective_pipeline_template` (ADR-011, TASK-132). The row keeps being the sole owner of what stays per-company: **`price_satang`, `commission_plan_type`, `commission_rate_type`, `affiliate_override_mode`, `pipeline_template_id`, `voucher_usage_quota`, `voucher_validity_days`, `requires_shipping`, `is_active`** (a company can still deactivate its own listing of a shared product without affecting other companies), and — unchanged — `commission_rules.product_id` still points at *this* per-company row, so BR-2/BR-4 commission resolution and the immutable ledger require **zero code changes**.

A validation rule on `UpdateProductRequest`/`StoreProductRequest` enforces the mutual exclusion: `catalog_item_id` set → `name`/`description`/`spec_description`/`brand_id`/`category_id` in the payload are rejected (or silently ignored server-side, ag-dev to decide the exact UX of that rejection) rather than silently accepted and discarded.

## 4. Why the 15 Dependent Tables Need Zero Changes

Every table that FKs into `products.id` (see Context) is scoped to the **per-company row**, which never moves, is never merged, and keeps its own primary key regardless of `catalog_item_id`. A referral against Thai Life's copy of the shared product still points at Thai Life's `products.id`; Genesenn's independent sale of the "same" product is a completely separate `products.id` with its own referrals/ledger entries. This is the entire reason the design links outward from `products` rather than restructuring it.

## 5. Governance — Why Super-Admin-Only, Both Layers

The human's answer makes Super Admin the sole writer for **both** the shared content (§2) *and* the per-company price/commission for any product that has opted into the shared catalog (§3, when `catalog_item_id` is set). Concretely:

- `ProductCatalogItemPolicy`/`CatalogBrandPolicy`/`CatalogCategoryPolicy`: `create`/`update`/`delete` → Super Admin only, full stop.
- `ProductPolicy::update()` gains a branch: if the target row's `catalog_item_id` is not null, **Company Admin is denied (403)** even for their own company's row — only Super Admin may change `price_satang`/commission fields/`is_active` on a catalog-linked product. Company Admin's view of such a row is read-only in the UI and 403 at the API if bypassed directly (defense in depth, same pattern as every other Policy in this codebase).
- Company Admin retains **full, unchanged** CRUD on any product with `catalog_item_id = null` — the governance restriction is scoped exactly to catalog-linked rows, not a platform-wide narrowing of Company Admin's existing powers.

## 6. Linking Flow

Linking is a deliberate Super Admin action, not a Company Admin self-service pick-and-adopt flow (consistent with §5's governance): Super Admin browses `product_catalog_items`, picks a company, and either (a) creates a brand-new per-company `products` row pre-linked (`catalog_item_id` set at creation, no name/brand/category asked — they come from the catalog item), or (b) links an *existing* standalone product to a catalog item after the fact (sets `catalog_item_id`, the row's own name/brand/category fields become dormant per §3). Either way, Super Admin sets that company's `price_satang` and commission configuration as part of the same action.

## 7. Data Migration Strategy

Zero migration of existing rows — `catalog_item_id` defaults `null` on every current `products` row, matching §1's additive principle. No automatic detection/merge of same-named products across companies (a name match is not proof of product identity — merging is a human decision, per-pair, made through §6's linking flow whenever a Super Admin chooses to do it).

## 8. Explicitly Out of Scope

- Sharing `brands`/`product_categories` themselves for **non-catalog-linked** products — those stay exactly as today, per-company, Company-Admin-managed. Only catalog-linked products resolve brand/category from the new global tables.
- Any UI for Company Admin to browse/request catalog items — not part of this ADR; §6 makes linking Super-Admin-initiated only. A future ADR could add a "request to sell this" flow if the human wants Company Admin self-service later.
- Storage-path tenant-scoping for the new global `product_media`/`product_specs` rows owned by `product_catalog_items` — Section 5 rule 6 ("tenant-scoped by path") was written for PDPA client documents; catalog product media is marketing content, not sensitive PDPA data, but the access-check story (a Company Admin who has *not* linked a given catalog item should arguably still not get an authenticated download URL for its media before linking) needs its own short design pass in TASK-212, not invented here.

## Sprint Breakdown

| Sprint | Task | Owner | Depends on |
|---|---|---|---|
| 1 | TASK-211 — Backend: migrations (`catalog_brands`, `catalog_categories`, `product_catalog_items`, `products.catalog_item_id` nullable FK, mutual-exclusion validation) | ag-dev | none |
| 2 | TASK-212 — Backend: Models, Policies (Super-Admin-only write gate on all 3 new tables + the `ProductPolicy::update()` branch from §5), Services (`ProductCatalogService` for shared-content CRUD + linking action), `ProductResource` "effective_" resolution for name/description/spec/brand/category, media/spec ownership access-check design (§8) | ag-dev | TASK-211 |
| 3 | TASK-213 — Backend: Super Admin API — CRUD `catalog_brands`/`catalog_categories`/`product_catalog_items`, link/unlink + set-price/commission endpoint, feature tests (tenant isolation: Company Admin write attempts on catalog-linked products/tables → 403) | ag-dev | TASK-212 |
| 4 | TASK-214 — frontend-admin (ag-ui): Super Admin screens — manage shared catalog (brand/category/product master, media/spec), per-company link + price/commission assignment screen | ag-ui | TASK-213 |
| 5 | TASK-215 — frontend-admin (ag-ui): `ProductEditView.vue` — catalog-linked products render name/description/spec/brand/category/media as **read-only** for everyone except the dedicated Super Admin catalog screen (TASK-214); price/commission tab also read-only for Company Admin on catalog-linked rows | ag-ui | TASK-213 |
| 6 | TASK-216 — ag-qa: verify tenant isolation (Company Admin 403 on all catalog-linked writes), standalone (`catalog_item_id = null`) products fully regression-free, BR-3/BR-4 unaffected, migration additive-only (no existing row mutated), the 15 dependent tables' behavior unchanged | ag-qa | TASK-211..215 |

## BR-7 Values Explicitly NOT Decided Here

Any actual price or commission value Super Admin sets per company for a linked catalog product — always entered live through TASK-214's screen, never invented here. Which existing same-named products (if any) get linked together, and by whom, and when — a per-pair human decision through §6's flow, not a migration default.

## Out of Scope (Restated)

- Re-deciding anything about the 6 commission plan types' own mechanics (Unilevel/Binary/Matrix/Stairstep-Breakaway/Generation/Affiliate) — untouched, ADR-011/ADR-035 stand.
- Multi-branch-within-one-company modeling — not what "สาขา" meant here (see decision table); no new sub-company entity is introduced.
- Company-Admin self-service catalog browsing/adoption — deferred, see §8.

---

## Amendment (2026-08-19, human decision — TASK-206)

**§3's "the row's own `brand_id`/`category_id` become unused" is amended: they stay POPULATED.**

Implementation nulled `products.brand_id` / `products.category_id` on link, for the same
"no stale local copy" reason as `name`/`description`. An audit (2026-08-19, prompted by the
human's question "หากสินค้าในหมวด หรือแบรนด์ มีการขายต่างบริษัทระบบจัดการอย่างไร") found three
readers of those two columns that are not display code:

1. **`CommissionService::resolveCommissionRule()`** — the category-scoped rung
   (`product_id IS NULL AND product_category_id = X`, TASK-028) cannot match a product whose
   `category_id` is null, so the sale silently falls through to the company-default rate.
   That is a wrong payout (BR-2) written to an immutable ledger row (BR-4).
2. **`PipelineTemplateResolver::categoryTemplateId()`** — the middle rung of ADR-026's
   `product ?? category ?? company` chain disappears; the referral gets the company-default
   journey instead of the category's.
3. **`ProductController::index`'s `brand_id`/`category_id` filters** — plus the Admin product
   list and the Agent Portal's `ProductBrowseView` facets: a linked product becomes unfindable
   by brand or category.

**Decision (human, Option A of three presented):** on link, the product keeps pointing at **its
own company's** `brands` / `product_categories` row, mirrored **by name** from the catalog item's
global `catalog_brands` / `catalog_categories`, created in that company if it does not exist yet.

- Display is unchanged: `ProductResource` still resolves name/brand/category from the catalog item,
  so a Super Admin renaming the shared brand still relabels every company at once.
- The local row is a **join target**, not a second source of truth.
- BR-6 holds: the mirrored rows carry the product's `company_id`, never the acting Super Admin's,
  and never a global row.
- `name` / `description` / `spec_description` are still cleared on link — those genuinely are
  display-only and have no non-display readers.

**Known consequence, accepted:** renaming a global catalog brand does not rename the mirrored
per-company rows (they were only ever a join target, and renaming them would fight a Company
Admin who edited their own copy). The two names can therefore drift; only the catalog's name is
ever displayed for a linked product. If that drift becomes a problem, the fix is a re-sync command,
not a live cascade — that would need its own ADR.
