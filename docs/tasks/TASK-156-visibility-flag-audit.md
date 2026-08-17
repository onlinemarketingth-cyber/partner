# TASK-156 — Visibility flags that were fields, not filters

- **Owner:** ag-lead (§2 done) → decision needed from the human (§3)
- **Date:** 2026-08-10 · **Found by:** the TASK-155 sweep
- **Related:** BR-6, CLAUDE.md §5 (multi-tenancy / IDOR), §6 (authorization), §8 rule 6

---

## 1. The bug class

A model carries a visibility flag — `is_active`, `is_published`, `status`, `published_at`,
`starts_at`/`ends_at` — the API Resource **exposes it as a field**, and no controller,
Service or Global Scope ever **applies it as a filter** on a route an Agent can reach.

The flag then means nothing a user can observe. Whatever hiding happens is client-side, in
the Vue app, which is not hiding — it is decoration. ADR-031 §2.2 already rejected exactly
this reasoning for sequential lesson locks; TASK-155 found the same shape on
`GET /modules`; this audit swept the rest of the API for siblings.

Ten were found. They split cleanly into two groups, and only one group is safe to fix
without a decision.

## 2. Fixed now — a detail route bypassing its own list's gate

These three are not design questions. In each case the correct rule **already existed** in
`index()` or on the model, and a second route simply did not consult it. Ids are
sequential, so "the list filters it" is not mitigation.

### 2.1 `AnnouncementController::show` — an Agent could read any announcement by id

`index()` applies a substantial gate: publication window (`published_at <= now`, not
expired) **and** audience targeting, including TASK-042's `exact` / `and_above` cert-tier
modes. `show()` applied **none** of it, and `AnnouncementPolicy::view()` returns `true` for
anyone in the company.

An Agent who knew or guessed an id could read a draft, a post scheduled for next month, an
expired one, or one addressed to a cert tier they have not earned.

**Fix:** the gate moved to `Announcement::scopeVisibleToAgent()` and both routes now run
**the same SQL**. A scope rather than an `isVisibleTo(User)` PHP predicate deliberately —
a hand-written PHP re-implementation of the `and_above` `sort_order` comparison is exactly
the second implementation that drifts, and the drift is what caused this. `show()` answers
404, not 403: an unpublished post's existence is itself the withheld thing.

### 2.2 `AgentPromotionController::show` — scheduled bonus promotions readable by id

`index()` filters on `isCurrentlyActive() && appliesToAgent()`.
`AgentPromotionPolicy::view()` checks only the second. So a promotion scheduled for next
quarter, or one that ended months ago, was readable by id — bonus amount, targets and all.
Promotions pay real money (TASK-042).

**Fix:** `show()` now applies both of the model's own predicates. 404, same reasoning.

### 2.3 `ProductRecommendationService::recommended` — auto-fill ignored `is_active`

The two halves of one row disagreed. The **pinned** half filters `is_active`; the
**auto-fill** half did not. `ProductGradingService` ranks on historical revenue, and a
discontinued product's revenue does not go away — so a deactivated former best-seller
could not be pinned into the recommended strip but walked back into it automatically. An
admin who switched a product off saw it still being promoted.

**Fix:** one `where('is_active', true)` on the auto-fill query.

## 3. DECIDED — "ปิดการใช้งาน" means hidden everywhere

**Human, 2026-08-10:** *"ปิดการใช้งาน ซ่อนทุกที่"*

Taken at face value with **one boundary**, stated here so nobody has to guess later and so
the human can overrule it if I have read them wrong:

> **Hidden everywhere it can be DISCOVERED or CHOSEN. Still resolvable where an existing
> record already points at it.**

The boundary is not a softening of the decision — it is what makes it implementable.
`CommissionLedgerResource`, `OrderResource` and `ReferralResource` all read the **live**
`product` relation for the product's name (TASK-047 snapshotted the *price* and the
promotion onto the ledger, **not the name**). A blanket filter — or a Global Scope on
`Product` — would therefore render `product: null` on an agent's own paid commission rows,
their order history and their client files, for every product the company has since
discontinued. A commission row with a blank product name is worse than the leak it fixes,
and it would look like data loss rather than a policy.

So: **an inactive product cannot be browsed, searched, recommended, shared, picked in a new
referral, or bought.** It can still be named by a record that already happened.

**If you meant history too** — i.e. an old order should show "สินค้าถูกยกเลิก" instead of
the name — say so and it becomes a schema task: the name has to be snapshotted onto
`orders` / `commission_ledger` / `referrals` at write time, exactly as BR-4 already
requires for money. That is not a filter change.

### Scope of the change (owner: ag-dev, spec in §5)

| Endpoint | Rule for an Agent |
|---|---|
| `BrandController::index` | `is_active` only |
| `ProductCategoryController::index` | `is_active` only |
| `ProductController::index` | `is_active` only — a rule, not the current opt-in `?is_active=` |
| `ProductController::show` | 404 when inactive |
| `StorefrontBannerController::index` | `is_active` only |
| `ProductRecommendationPinController::index` | `is_active` only |
| `ProductPricePromotionController::index` | currently-active window only |
| `RewardItemController::index` | `is_active` only |
| `ProductShareCheckoutService` | refuse checkout for an inactive product |
| **Untouched** | the `product` relation on ledger / order / referral / client resources; the nested `/products/{id}/media`, `/specs`, `/sales-materials` (reached only from a record you already hold) |

Admins keep seeing everything on all of the above — they are the ones toggling the flag.

### 3.1 Gaps closed after ag-dev's report (ag-lead, same day)

ag-dev implemented the table and then flagged three things it did not cover. Two were
genuine holes in **my** table, not in their work:

- **`PublicProductShareController`** — "shared" was in the decision and missing from the
  table. A share link outlives the product: an agent sends it, the company later
  discontinues the product, and the customer could still open the showcase and **check
  out**. That is the most expensive form of this bug — it ends in an order and a
  commission. Closed at `resolveUsableLink()`, the one choke point every public route
  passes through (showcase, media, sales material, checkout). 404, matching the
  revoked/expired answer beside it.
- **`ProductPricePromotionController::show`** — the same detail-route gap as §2.1/§2.2,
  which I should have caught when I wrote §2. An Agent guessing an id read an unannounced
  future price cut. Now gated on `isCurrentlyActive()`.
- **Banners pointing at a deactivated product** — the banner's own flag was not the whole
  answer. Since `ProductController::show` now 404s for Agents, such a banner would render
  and then dead-end on tap. Advertising something that cannot be opened is worse than the
  leak. Filtered, keeping `url`/`internal` banners (TASK-073) untouched.

**Recommendation pins:** ag-dev left these and gave the right reason — the list an Agent
actually sees is `GET /products/recommended`, which already excludes inactive products on
both its pinned and auto-fill halves (§2.3). `/product-recommendation-pins` is Admin CRUD.
No change; recorded so it is not "fixed" later by someone reading the table alone.

## 4. Superseded — the original open questions

Seven endpoints expose `is_active` / `status` without filtering. I have **not** touched
them, because the fix depends on a question nobody has answered and guessing it could
break something that matters more than the leak:

> **What does "inactive" mean — hidden from browsing, or hidden everywhere?**

A deactivated product is the sharp case. It must disappear from the storefront. It must
**not** disappear from an Agent's past orders, their commission history, or a client file
that references it — a commission ledger row pointing at a product that 404s is worse than
the leak. So a blanket filter on `ProductController::show` is not obviously correct, and
`index` may need to stay filterable rather than filtered.

| # | Endpoint | Flag | Today |
|---|---|---|---|
| 1 | `BrandController::index` | `is_active` | no filter; Vue filters client-side |
| 2 | `ProductCategoryController::index` | `is_active` | no filter; Vue filters client-side |
| 3 | `ProductController::index` / `show` | `is_active` | opt-in `?is_active=` only; `show` none |
| 4 | `StorefrontBannerController::index` | `is_active` | opt-in only |
| 5 | `ProductRecommendationPinController::index` | `is_active` | opt-in only |
| 6 | `ProductPricePromotionController::index` | `status`, `starts_at`, `ends_at` | filters on `product_id` only |
| 7 | `RewardItemController::index` | `is_active` | company scoping only |

**#6 is the one I would raise first.** `ProductPricePromotion::isCurrentlyActive()` exists
and is never called on the read path, so Agents can see **unannounced future price cuts**
and expired promo pricing. That is commercially sensitive in a way brand names are not, and
unlike products it has no history argument — a promotion that has not started has nothing
to be historical about.

### The question, put concretely

1. **Products** — should a deactivated product 404 for an Agent, or stay readable by id
   (so orders and commission rows keep resolving) while disappearing from browse lists?
2. **Price promotions** — filter to the currently-active window on the Agent read path?
   (My recommendation: yes, and it is the one item here I would ship without further
   discussion if you say only one thing.)
3. **Brands / categories / banners / pins / reward items** — apply `is_active` server-side
   for Agents? These have no history argument I can find, so I expect yes, but they are
   seven routes across three screens and I am not changing what agents see on a guess.

## 4. Verification status — read this

**Not run.** There is no PHP runtime in the environment this work was done in, so
`php artisan test` and Pint were **not executed**. §2's changes are unverified beyond
reading. Run before merging:

```
php artisan test --filter=AnnouncementVisibility
php artisan test --filter=Announcement
php artisan test --filter=AgentPromotion
php artisan test --filter=Product
./vendor/bin/pint --test
```

The `AnnouncementController::show` signature changed (it now takes `Request` first), which
is the most likely thing to break an existing test.
