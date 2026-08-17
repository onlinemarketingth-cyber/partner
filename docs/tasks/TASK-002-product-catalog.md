Task: Product Catalog CRUD (Brand / ProductCategory / Product / CommissionRule)
Owner: ag-dev (backend), ag-ui (Admin screen) — executed directly by ag-lead, no separate ag-dev/ag-ui sessions running yet
Goal: Turn the ERD-001 rev. 3 "Product Catalog" schema into a working, authorized, tested feature — Policies, Form Requests, Services, API Resources, Controllers, routes, seed data, tests, and a real (non-placeholder) Admin screen.
Related: BR-2 (tiered commission config), BR-3 (money as integer satang), BR-6 (tenant isolation, highest priority), Section 5 rules 3/4/5 (Policies, visibility, IDOR guard), Section 6 (Form Request validation, mass assignment), Section 7 (Controller thin -> Form Request -> Service -> Model), ERD-001 §"Product Catalog"

Input:
- Migrations + models from TASK (schema pass, rev. 3): brands, product_categories, products, commission_rules
- Existing TenantScope, UserRole enum, CompanyPolicy pattern to mirror

Expected output:
- Policies: BrandPolicy, ProductCategoryPolicy, ProductPolicy, CommissionRulePolicy
- Form Requests: Store/Update x 4, under app/Http/Requests/Catalog/
- Services: Store/Update x 4, under app/Services/Catalog/ (company_id injection, CommissionRule overlap validation)
- API Resources: BrandResource, ProductCategoryResource, ProductResource, CommissionRuleResource
- Controllers: BrandController, ProductCategoryController, ProductController, CommissionRuleController (Api/V1)
- Routes: apiResource under /api/v1, behind auth:sanctum
- CatalogSeeder (cert_tiers + 1 brand + 1 category + 2 products at CLAUDE.md's real 8,900/9,900 THB price points + commission_rules with placeholder rate values)
- Feature tests: tests/Feature/Catalog/{Brand,Product,CommissionRule}Test.php
- Admin UI: frontend-admin/src/views/ProductCatalogView.vue (Brands/Categories/Products/Commission Rules tabs, wired to the real API), linked from AdminHomeView

Acceptance Criteria:
  - Agent role: can list/view Brand, ProductCategory, Product (read-only, needs it to know what to sell later); CANNOT create/update/delete any of the four; CANNOT view CommissionRule at all (403) — sensitive comp data
  - Company Admin / Super Admin: full CRUD within their own company; Super Admin may act cross-company by supplying company_id explicitly
  - Cross-tenant access to a specific record ID -> 404 (TenantScope filters route-model-binding) — verified by BrandTest::test_company_admin_cannot_view_another_companys_brand and ::cannot_update
  - price_satang: integer only, float/negative rejected (BR-3) — verified by ProductTest
  - commission_rules.product_id / products.brand_id / products.category_id: rejected if they belong to a different company than the actor, even if IDs are guessed correctly (BR-6) — verified by ProductTest::test_cannot_create_a_product_with_another_companys_brand
  - Overlapping commission_rules effective date ranges for the same (company, cert_tier, product) are rejected (BR-2, CommissionRuleService::assertNoOverlap) — verified by CommissionRuleTest
  - No BR-7 value is hardcoded in application code — CatalogSeeder's placeholder rate/description values are seed data, clearly commented `// TODO: CONFIRM (BR-7)`, and swappable without touching any Service/Controller
  - company_id is never accepted from the client for non-Super-Admin actors — always forced server-side (BrandService et al.)
  - Admin UI money display divides satang by 100 only in the Vue component (BR-3 "divide by 100 only at the UI display layer") — API always transmits raw integer satang

Verification status:
  - Structural review passed via two independent subagent passes (schema layer, then this feature layer) — found and fixed one real blocking bug (missing AuthorizesRequests trait on the base Controller) and one hardening gap (Service-level company_id null-check)
  - Frontend: npm run lint (oxlint + eslint) and npm run build (vue-tsc type-check + vite build) both actually run and passed clean in a throwaway /tmp copy
  - Backend: `php artisan test --filter=Catalog` actually run on the human's machine. First run surfaced a real Laravel 11/12 gotcha static review couldn't catch — `authorizeResource()` calls the instance `$this->middleware()` helper, which no longer exists on the default skeleton `Controller` base class — fixed by extending `Illuminate\Routing\Controller`. Also hit a duplicate-seed crash (`Company::factory()->create()` isn't idempotent, `slug` is unique) — fixed by switching both seeders to `firstOrCreate`. Re-run after both fixes: **passing.**
  - Manual UAT (docs/qa/UAT-001-product-catalog.md): not yet confirmed by the human — do this before treating Phase 1 as fully closed, not just unit-tested.

Out of scope (future tasks):
  - GET /cert-tiers endpoint (Academy phase) — the Admin commission-rule form currently resolves cert_tier_id by scanning existing commission rules, which fails for the very first rule of a brand-new company; flagged as a TODO in ProductCatalogView.vue
  - Agent Portal exposure of the catalog (needed once Referral submission is built)
  - Academy, Customer, Referral & Pipeline, Commission Ledger (BR-4 calculation itself), Gamification, Admin's other modules (manage agents, gamification rules, manage companies) — next phases per the phased rollout the human chose

Design notes (not in CLAUDE.md, decided here — flag if wrong):
  - CommissionRule read access is Company Admin/Super Admin only, excluding Agent — CLAUDE.md doesn't say this explicitly, but Section 2 frames Company Admin as the one who "manages data," and Agent's own Commission screen already shows the ledger (earnings), not the rate table. Reasonable default, not a BR-7 guess (it's an authorization architecture call, not a business number) — say so if Agent should see rates directly.
  - Brand/ProductCategory modeled as independent dimensions on Product (ERD-001 open question #1, still open).
