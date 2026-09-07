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

---

## Amendment 1 (2026-09-07) — §7's open question, answered by shipping it

§7 above left one question deliberately open: *"Brands and categories are not made central in this ADR … that is TASK-253's first design question, and it gets answered there with the readers in front of us."* It was answered, and the answer belongs here rather than only in a commit message.

**Brands and categories DID become platform-capable**, by the same rule as products: `company_id` nullable, read through `SharedOrTenantScope`, written by Super Admin only. A shared product cannot sit inside one company's taxonomy — the other companies cannot see those rows at all — so `catalog:promote-products` repoints a promoted product at a platform brand and category, matched by NAME, and leaves the company's own rows exactly where they are (other products still point at them).

The three non-display readers ADR-036 Amendment 1 identified were each handled explicitly:

- **A category-scoped commission rule** matches through `product_category_id`. Repointing the product would make it stop matching SILENTLY, sending the sale to the company default rate and writing a wrong payout into an immutable ledger (BR-2/BR-4). The command **refuses**: it skips such a product and names the rule. That is a human decision, and not one a migration takes at 3am.
- **`PipelineTemplateResolver`** — a template belongs to one company, so a shared product carries none. `pipeline_template_id` is cleared on promotion and the journey then resolves per company (category → company default, ADR-026 §3.3), which is the right answer for every company including the original.
- **The brand/category filters** are display-only and follow the taxonomy.

### What the rest of the sprint turned out to be

| Task | What it was |
|---|---|
| TASK-256 | The admin row, plus `CompanyScopeFilter::contextCompanyId()`. Every resolved field answered for `$request->user()->company_id` — correct for a Company Admin, wrong for the only role allowed to edit these, because a Super Admin belongs to no company. The header picker already travels as `?company_id=`; that IS their context. Read only for a Super Admin, exactly as `apply()` has always done. |
| TASK-245 | Eight admin controls a Company Admin could click that the server would refuse. Not one was a missing rule — every refusal was correctly enforced; the screens re-derived "may I?" from the nearest-looking field. Brand/Category/Product resources now carry the Policy's own answer per row. `set_commission_rule` is deliberately NOT derived from `update`: this ADR keeps commission per company, so a Company Admin may price their own commission on a shared product they may not otherwise touch. |
| TASK-257 | The money-facing half, and the one that mattered most. §4 predicted it: *"every caller must pass the company."* Five resources had not been given one — the public share page, the affiliate landing page, the deal card, the storefront pin and the promotion form. `PublicProductShareResource` was the worst: it advertised the CENTRAL price while `OrderService::createForReferral()` charged the sharing company's, which is TASK-136's risk R1 re-opened without anybody editing either file. The affiliate page also filtered its catalogue on `company_id`, excluding every shared product outright. |

### The trap this re-model leaves behind

`Product::withoutGlobalScope(TenantScope::class)` no longer removes the scope, because the scope is now `SharedOrTenantScope`. It compiles, it runs, and it silently narrows the query. A source-scanning test guards it; anything new that strips a scope from `Product` must name `SharedOrTenantScope`.
