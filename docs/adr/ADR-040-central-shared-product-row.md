# ADR-040: One Product Row, Shared By Every Company (supersedes ADR-036's copy model)

- **Date:** 2026-09-05
- **Status:** Accepted — scope locked by the human's answers below. Every price and commission value inside this scope remains **undecided (BR-7)** and is entered live, never invented here.
- **Author:** ag-lead
- **Supersedes:** ADR-036 §3 (per-company row), §6 (linking flow), §7 (no migration), and its Amendment 2 (TASK-251 propagation)
- **Keeps from ADR-036:** §2 (the global catalog layer), §5 (Super-Admin-only writes), the Amendment 1 reasoning about `brand_id`/`category_id` having non-display readers
- **Precedent:** TASK-217 / `theme_presets` — `company_id NULL` means "platform-wide", read through `SharedOrTenantScope`, written by Super Admin only

## Context — what went wrong, plainly

ADR-036 (2026-08-18) answered "the same product sold by several companies" with **one `products` row per company**, joined by a shared `product_catalog_items` row that carried the name, brand, category and spec. I chose that shape, and I chose it for a defensible reason: fifteen tables FK into `products.id`, two of them money records that must never be rewritten (`commission_ledger`, BR-4), so linking outward from `products` avoided touching any of them.

TASK-251 (2026-09-04) then made that model automatic — a new catalog item created a row in every company. On 2026-09-05 the human opened the product screen, saw **eight rows where the business has four products**, and said:

> "คุณต้องปรับทั้งโครงสร้าง หรือปรับ DB คือโจทย์สินค้าใช้ร่วมกัน แต่ที่คุณทำคือการ Copy ไปไว้อีกบริษัทหนึ่งมันผิดโจทย์ … ตัวสินค้า academy จะเป็นระบบกลาง ไม่เพิ่มแบบ copy"

That is correct. "ใช้ร่วมกัน" means one record that every company uses. A row per company is a *join*, not sharing: it looks identical on a demo database and diverges the moment anybody edits one copy — and it makes the honest answer to "how many products do we sell?" impossible to read off the screen.

The August decision optimised for not touching risky tables. The right thing to optimise for was the model being true. This ADR reverses it.

### Locked-in decisions (human, 2026-09-05)

| Question | Decision |
|---|---|
| The copies now on production | **Roll back first** (`catalog:undo-adopt-products`), then re-model on a clean database |
| Who sets price / commission / on-off per company | **Super Admin only** — unchanged from ADR-036 §5 |
| A company that has not set its own price | **Falls back to the central price** — not "unsellable" |
| The existing catalog layer (`product_catalog_items`, `catalog_brands`, `catalog_categories`) | **Keep both layers** — the catalog stays the shared identity; the central product is the sellable thing |

## 1. The Shape

Three levels, each with exactly one job:

```
product_catalog_items        identity        name · brand · category · spec · media   (already exists, global)
        ↑
products (company_id = NULL) the sellable    one row per real product, central price   (NEW: company_id nullable)
        ↑
company_product_settings     per company     price override · on-off · commission cfg  (NEW table)
```

- **`products.company_id` becomes nullable.** `NULL` = a central product every company sells; a value = today's company-owned product, entirely unchanged. This is the exact convention `theme_presets` has used since TASK-217, and `SharedOrTenantScope` already implements the read rule (`company_id = :own OR company_id IS NULL`) with its own tests and its own note about why the `OR` must be nested.
- **`company_product_settings`** (`company_id`, `product_id`, `price_satang` nullable, `is_active` default false, commission columns nullable, unique on the pair) is the only per-company state. No duplicated name, brand, category, description or media — those have exactly one home.
- **The fifteen dependent tables are not modified.** Every one of them already carries its own `company_id` (verified table by table on 2026-09-05), so a referral, order or ledger row against a central product still knows which tenant it belongs to. This is what makes the re-model possible without touching a single money record.

## 2. Resolution — the two questions a screen asks about a product

**"What does it cost here?"** — `ProductPricingService::effectivePriceSatang()` is already the single answer to this in the entire codebase (TASK-136 collapsed two disagreeing implementations into it; `OrderService`, `CommissionService` and the public share page all read it). It gains the company as context and resolves:

```
company override  ??  the central product's own price_satang
```

The fallback is the human's decision above, and it is safe because the central price is a number a Super Admin typed — not an invention. BR-7 is satisfied; BR-3 stays integer satang end to end.

**"Is it on sale here?"** — the company's `is_active`, which **defaults to false**. Price falls back; permission to sell does not. That preserves the earlier "ปิดไว้ก่อน" decision: a product appearing in the catalog is not a product a company has decided to sell, and the two must not be conflated just because the price question has an answer.

## 3. Governance (unchanged from ADR-036 §5)

Super Admin writes the central product, and Super Admin writes each company's settings row. Company Admin reads. The re-model does not widen anybody's powers — it only stops the platform from expressing "shared" as duplication.

## 4. What This Costs, Honestly

The dangerous surface is not the schema; it is `TenantScope`. Widening product reads to include ownerless rows is a change to the rule that keeps tenants apart (BR-6, the highest-priority rule in this codebase). It is mitigated by using the scope that already exists and is already tested rather than writing a second one, and by tenant-isolation tests written before the switch.

The second surface is price resolution. There is one choke point today; there must still be exactly one afterwards, and every caller must pass the company rather than defaulting to "the acting user's", because a Super Admin acting across tenants has no company of their own.

## 5. Migration

`products.id` never changes. The four existing Thai Life products are promoted in place — `company_id` set to `NULL`, a settings row created for Thai Life carrying their current price and `is_active = true`, and a settings row for every other company carrying no price (so they inherit the central one) and `is_active = false`. Referrals, orders, ledger rows and commission rules keep pointing at the same ids and are never rewritten.

By hand, through a command, with `--dry-run` — never a migration that runs itself during a deploy. Same reasoning as TASK-251's adoption command, which is also the reason today's rollback is possible at all.

## 6. Sprint Breakdown

| # | Task | Content |
|---|---|---|
| 1 | TASK-253 | Schema: `products.company_id` nullable · `company_product_settings` · `SharedOrTenantScope` on Product · tenant-isolation tests written first |
| 2 | TASK-254 | Resolution: pricing per company, sellability per company, `ProductResource` effective fields, Policies |
| 3 | TASK-255 | Migration command: promote the existing products in place, create the settings rows |
| 4 | TASK-256 | Admin UI: one row per product, per-company price and on-off; the catalog screen keeps managing identity |
| 5 | TASK-257 | Agent Portal + QA: an agent sees only what their company switched on, at their company's price; orders, commission and the ledger regression-free |

## 7. What Is Explicitly Not Being Done

- **`commission_ledger` and every existing order are untouched.** They snapshot amounts (BR-4); nothing in this ADR rewrites a money record.
- **Brands and categories are not made central in this ADR.** ADR-036 Amendment 1 documented that `products.category_id` has three non-display readers (category-scoped commission rules, pipeline templates, the product filters), and a central product needs a category those readers can still match. That is TASK-253's first design question, and it gets answered there with the readers in front of us — not assumed here.
- **Company Admin self-service pricing** — still Super Admin only, per the decision table.
