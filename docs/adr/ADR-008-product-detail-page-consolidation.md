# ADR-008: Product Detail Page Consolidation (Full-Page Edit/Create, Spec PDF Gallery)

- **Date:** 2026-07-17
- **Status:** Accepted — human-confirmed 2026-07-16/17 (wireframe + 4 clarifying answers + "ย้ายทั้งหมด"). Implementation in progress this session.
- **Author:** ag-lead

## Context

Human provided a hand-drawn wireframe for a full product edit/create screen (name, hero+thumbnail gallery, free-text description with pencil-edit, inline-editable Commission panel, price, and a separate free-text "spec" block with its own image+PDF gallery), then answered four clarifying questions:

1. Free-text description AND free-text spec narrative are **both additive** — the existing key-value `product_specs` table (ADR-007) stays, nothing is replaced.
2. The spec gallery must accept **PDF** uploads (not just image/video like today's `product_media`), with a thumbnail, and clicking must open a **full multi-page in-app viewer**.
3. The Commission panel on this page must be **directly editable**, not read-only.
4. This becomes **two new dedicated full pages** ("create product", "edit product"), replacing today's inline expandable-table-row UX in `ProductCatalogView.vue`.

A follow-up question (what happens to Sales Materials/share-links and the Video Settings tab, both currently living elsewhere in `ProductCatalogView.vue`) was answered **"ย้ายทั้งหมด" (move everything)**.

## Decisions

1. **`products` table gains one new column**: `spec_description` (nullable text). `description` (free-text product description) **already exists** on `products` since ADR-007/initial schema — no migration needed for it, only for `spec_description`.
2. **New table `product_spec_attachments`**, not an extension of `product_media`. Mirrors `product_media`'s shape exactly (`media_type`, `source_type`, `file_path`/`embed_url`, `thumbnail_path`, `sort_order`, `processing_status`) but with its own `media_type` enum (`image|pdf` — no video; a PDF spec sheet doesn't need video) and an added `page_count` (nullable uint, PDF only). Kept as a separate table/enum rather than overloading `product_media`'s `image|video` enum, because `product_media` is documented (ADR-007) as the *hero/thumbnail gallery* specifically, and mixing a `pdf` case into it would force every existing `product_media` consumer (stream/thumbnail endpoints, frontend gallery grid) to special-case a type it was never designed for.
3. **PDF thumbnail generation**: a new queued job `GeneratePdfThumbnail`, following `CompressUploadedVideo`'s exact pattern — shells out to the system **`pdftoppm`** binary (poppler-utils; same "no new Composer dependency, use a system binary already documented in SETUP.md" convention as `ffmpeg`) to render page 1 to a JPEG thumbnail, and **`pdfinfo`** to read the page count. Graceful degradation identical to video: if the binary is missing or the job fails, the original PDF stays fully usable (streamable/viewable), only the thumbnail is missing (frontend falls back to a generic PDF icon) and `processing_status` flips to `failed`, logged for a human. **Deployment note**: server needs `poppler-utils` installed (`pdftoppm`, `pdfinfo`) — added to SETUP.md alongside the existing `ffmpeg` requirement.
4. **Full multi-page in-app PDF viewing** is a frontend-only concern: the existing authenticated-stream pattern (`product-media.stream`-style route, `Storage::disk(...)->response(...)`) is reused as-is for `product_spec_attachments`; the frontend fetches the PDF as a blob (same `api.getBlob()` pattern as `AuthenticatedMedia.vue`) and renders it with `pdfjs-dist` (new frontend dependency — nothing else in this repo does client-side PDF rendering) in a new full-screen viewer component.
5. **Commission panel**: no backend change — reuses the existing `commission-rules` CRUD endpoints as-is, just rendered/edited inline on the new product page instead of the separate `commission_rules` tab, filtered client-side to the one product being edited (matches how the current tab already filters).
6. **Sales Materials + share-links + Video Settings**: per "ย้ายทั้งหมด", their UI moves into the new product page too. **Flagging one structural mismatch rather than silently complying (Guardrail 6):** Sales Materials and share-links are genuinely per-product (`product_sales_materials.product_id`) and belong on this page. **Video Settings is company-wide config** (`video_processing_settings`, one row per company, governs ffmpeg compression target for every video upload across every product/module) — it has no `product_id` and isn't conceptually about a single product. Moving its *form* onto every product's edit page would mean editing the same company-wide row from N different places, which is confusing and easy to accidentally diverge. **Resolution applied**: Sales Materials + share-links move fully into the new page (per instruction). Video Settings' form is *not* duplicated per-product; instead the product page gets a small "ตั้งค่าคุณภาพวิดีโอ" link that jumps to the existing company-level settings location. If this isn't what was meant, flag it back — trivial to change.
7. **Two new routes**, one view: `frontend-admin/src/views/ProductEditView.vue` handles both create (`/product-catalog/products/new`) and edit (`/product-catalog/products/:id/edit`) via an optional route param, consistent with how Laravel/Vue projects typically avoid duplicating a large form between create/edit. `ProductCatalogView.vue`'s "products" tab loses its expandable-row panel (media/specs/materials moved out) and its rows now link to the edit route instead.

## Out of scope

- PDF annotation/markup, PDF editing, PDF form-filling — view-only.
- Multi-file batch PDF upload in one action — one file per upload action, same as existing `product_media`/sales-material patterns.
- Real-time collaborative editing of the product page (last-write-wins, same as every other form in this app).
