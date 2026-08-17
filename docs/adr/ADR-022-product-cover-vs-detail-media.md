# ADR-022 — Separating product photos (`cover`) from the detail gallery (`detail`)

- **Status:** Accepted
- **Date:** 2026-08-04
- **Task:** TASK-097 (supersedes the UI-only TASK-096)
- **Related:** ADR-007 (product media), ADR-020 (storefront redesign), BR-6 (tenant isolation)

## Context

`product_media` held one undifferentiated list. Two different things were
being read out of it:

1. **The storefront card image.** `ProductResource::thumbnail_url`
   resolved `is_primary ?? first`, and `StorefrontBannerResource` did the
   same. Whatever won became the picture every agent and every customer
   saw first.
2. **The product detail gallery** — long-form images, uploaded video and
   YouTube embeds shown on the product page.

TASK-096 surfaced `is_primary` as a "รูปสินค้า (หน้าปก)" slot in the
Admin product editor. That made the card image visible and settable for
the first time, but it did not separate the two concerns: the cover WAS a
row in the detail gallery, so uploading a product photo also made it
appear under รายละเอียดสินค้า.

The human reported exactly that, and added a second requirement:

> "ทำไม up รูปปกสินค้า แล้วรายละเอียดสินค้าขึ้นด้วย ต้องแยกกัน
> และรูปสินค้าสามารถ Upload ได้หลายรูปเหมือน Shopee"

So the target is the Shopee/Lazada model: a product has a **set** of
photos, one of which leads; the detail content is separate material.

## Options considered

### A. `products.cover_image_path` column (single image)

- **Pro:** dead simple; no relationship to reason about.
- **Con:** only one image, which fails the stated requirement outright.
- **Con:** a second storage/stream/delete path duplicating everything
  `ProductMediaService` and `ProductMediaController` already do.

### B. New `product_cover_images` table

- **Pro:** the cleanest conceptual separation.
- **Con:** every column, the tenant scope, the private-disk storage, the
  stream/thumbnail/download routes, the Policy and the tests would be
  copied verbatim from `product_media` to express one boolean's worth of
  difference. Two near-identical services drift; the first thing to drift
  would be BR-6 enforcement.

### C. `product_media.purpose` enum column *(chosen)*

- **Pro:** one column; everything else — TenantScope, the private disk,
  the access-checked stream endpoints, the Policy, the chunked-upload
  middleware — is reused unchanged.
- **Pro:** backfillable, so existing storefront cards keep their image.
- **Con:** the two galleries share a table, so every query that means
  "just the photos" must remember to filter. Mitigated by making the
  API's `?purpose=` filter and `Product::media()`'s cover-first ordering
  the obvious paths, and by covering the resolution order with tests.

## Decision

**Option C.** Add `product_media.purpose` (`App\Enums\ProductMediaPurpose`
= `cover` | `detail`), defaulting to `detail`.

Rules:

1. **`cover` is images only.** Enforced in `StoreProductMediaRequest`, not
   just the UI — a video cover renders the storefront card as an empty
   box, and nothing on screen would explain why.
2. **The first cover of a product auto-becomes `is_primary`**
   (`ProductMediaService::store`). Uploading and then having to click a
   star is a state where the card silently falls back to "first media".
3. **Deleting the primary cover promotes the next one**
   (`promoteNextCover`). "Covers exist but none is primary" is the same
   silent-fallback failure.
4. **`is_primary` now only means anything within the cover set.** The
   set-as-primary star was removed from the detail gallery in the Admin
   UI accordingly.
5. **Resolution order for the card** (`ProductResource::thumbnail_url`,
   mirrored in `StorefrontBannerResource`):
   primary cover → any cover → primary anything → first anything.
   The last two steps exist only for products whose photos still live in
   the detail gallery; dropping them would blank those cards.
6. **`Product::media()` orders `purpose` before `sort_order`**, so the
   public share-page carousel leads with the product photos.

## Migration / data

`2026_08_16_090000_add_purpose_to_product_media_table` adds the column
(default `detail`) plus a `(company_id, product_id, purpose, sort_order)`
index, then backfills `purpose = 'cover'` for every row already flagged
`is_primary`.

That one row per product is precisely what `thumbnail_url` was already
resolving, so **no existing storefront card changes**, and the new
รูปสินค้า section is populated on first load rather than looking empty.
The backfilled photo does leave the detail gallery — that is the intended
semantic, since it is the product photo.

## Consequences

- **Admin:** รูปสินค้า is a multi-image grid (upload tile always last,
  Shopee-style), with set-primary and delete per tile. รายละเอียดสินค้า
  is filtered to `purpose != cover` and no longer shows or sets a primary.
- **Agent Portal:** no code change. The card reads `thumbnail_url`; the
  public share carousel reads `product.media`, which is now cover-first.
- **Uploads:** covers go through the same `postFileWithProgress` path as
  everything else, so TASK-094 chunking applies unchanged. They are
  uploaded **sequentially**, not in parallel, so the "first cover becomes
  primary" check in the service cannot race.
- **Open:** no drag-to-reorder yet. `sort_order` exists and is honoured;
  ordering is currently upload order. Add it when someone asks — it is a
  `PUT /product-media/{id}` with `sort_order`, already supported.
