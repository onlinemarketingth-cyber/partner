# ADR-007: Product Media/Specs, External Sales Sharing, Academy Video

- **Date:** 2026-07-15
- **Status:** Accepted — human-confirmed 2026-07-15. Implementation in progress this session.
- **Author:** ag-lead

## Context

Human request (verbatim, translated): "1) Product detail page as complete as Amazon-standard e-commerce data entry — images, video, spec data. 2) Sales manual — clips, images, PDF, and external sharing 1-to-many. 3) Academy that can embed via iframe or accept uploaded video/content."

This is three feature areas. Per CLAUDE.md Guardrail 7, ag-lead surveyed the current schema first (Product has no media/spec infrastructure; ProductSalesMaterial exists but PDF/image only, private-disk-only; Module already has a flexible `content_type`/`content_ref` design but no video upload or iframe rendering) before proposing options, then presented trade-offs and got explicit human decisions rather than guessing.

## Decisions (human-confirmed 2026-07-15)

1. **Video storage:** support **both** — embed external link (YouTube/Vimeo unlisted) AND upload directly to our own server. Used identically across all 3 features (Product, Sales Materials, Academy).
2. **Product spec fields:** **admin-configurable key-value table** (not a fixed physical-goods schema), explicitly designed to cover both a physical-product style spec set and a health-package style spec set (coverage limits, hospital network, age eligibility, waiting period, etc.) — per BR-7, no spec taxonomy is hardcoded.
3. **External sales-material sharing (1-to-many):** **signed public link with an expiry date.** This is a deliberate, narrow, documented exception to CLAUDE.md §5 rule 6 ("never a public URL") — the rule as originally written targeted PDPA client documents and internal sales collateral behind auth; a sales manual an agent hands to a *prospect who has no account* is a different case by nature. The exception is scoped tightly: one link = one specific pre-authorized file, time-limited, revocable, and only ever mintable by an authenticated same-company user via `ProductPolicy::view` — it never opens a listing or any other company's data.
4. **Video compression:** self-hosted uploads are compressed server-side (async, queued job) before being usable, with **admin-configurable** (per company) limits — max upload size, target resolution, target bitrate — never hardcoded (BR-7). Falls back to a platform-wide default in `config/media.php` when a company hasn't set an override.

## Architecture

**One shared "media" shape, reused three times** (not three divergent designs):
`source_type` (upload | embed) + a value column, so every consumer decides at read-time whether to render an `<iframe>`/external `<video src>` or stream through our own access-checked endpoint.

- **`product_media`** (new table) — Product's image/video gallery. `media_type` (image|video), `source_type` (upload|embed), `file_path`/`embed_url` (mutually exclusive by `source_type`), `thumbnail_path` (nullable — only generated for uploaded video), `is_primary`, `sort_order`, `processing_status` (pending|processing|ready|failed — only meaningful for `source_type=upload` + `media_type=video`).
- **`product_specs`** (new table) — `spec_group` (nullable, e.g. "ข้อมูลทั่วไป"/"ความคุ้มครอง"), `spec_key`, `spec_value`, `sort_order`. Fully admin-driven, no fixed taxonomy.
- **`product_sales_materials`** (existing table, extended) — gains `source_type`, `embed_url`, `processing_status`. Existing `file_path`/`mime_type` columns keep their exact existing meaning for the upload case; `embed_url` is a new sibling column used only when `source_type=embed`. Video mime types (`mp4`, `mov`, `webm`) added to the upload allow-list, size cap sourced from `video_processing_settings` instead of the material's own hardcoded `max:15360`.
- **`modules`** (existing table, extended) — gains `source_type` (nullable — only meaningful for `content_type=video`) and `processing_status`. **`content_ref` is reused as-is** for both the embed URL and the uploaded file's private-disk path — this was already the model's documented design ("opaque string, caller determines structure"), so no new column needed here, unlike sales materials where `file_path` already carries a narrower, pre-existing meaning that shouldn't be overloaded.
- **`sales_material_share_links`** (new table) — `sales_material_id`, `token` (unique, random), `expires_at`, `revoked_at` (nullable), `created_by_user_id`, `view_count`. A public, unauthenticated `GET /share/sales-materials/{token}` route streams the file if not expired/revoked. Generation is authenticated + Policy-checked (`ProductPolicy::view` on the material's product) exactly like every other access in this app; only the resulting token is unauthenticated, and it grants access to exactly one file, not a listing.
- **`video_processing_settings`** (new table, one optional row per company) — `max_upload_mb`, `target_resolution` (e.g. `720p`), `target_bitrate_kbps`. `config/media.php` holds the platform-wide fallback (200MB / 720p) used when a company has no row — this satisfies BR-7's "admin-editable, never hardcoded into logic" while keeping a sane zero-config default.
- **Video compression** — a queued Job (`App\Jobs\CompressUploadedVideo`) shells out to the system `ffmpeg` binary via Symfony Process (already a Laravel dependency — no new Composer package added, consistent with this project's established preference against adding dependencies where a standard-library approach works). Runs after the original upload is safely stored; on success, replaces the stored file with the compressed version and flips `processing_status` to `ready`; on failure (e.g. `ffmpeg` not installed on the server), leaves the original upload usable as-is and flips `processing_status` to `failed` rather than blocking the feature — logged for a human to investigate. **Deployment note:** the target server needs the `ffmpeg` binary installed and a queue worker running (`php artisan queue:work`, `QUEUE_CONNECTION=database` already configured) — see SETUP.md.

## Scope trim (ag-lead call, not a business-value decision — flagged per Guardrail 6)

Thumbnail generation for Academy module videos is **out of scope for this pass** (Product/Sales Material galleries still get thumbnails, since they're browsed visually; an Academy video is played inline from a single module page, where a thumbnail matters far less). Can be added later without a schema change (there's already a `thumbnail_path`-shaped column pattern established on `product_media`).

## Out of scope

- Video transcoding to multiple quality levels / adaptive streaming (HLS/DASH) — a single compressed MP4 per upload only.
- Analytics beyond a raw `view_count` on share links (no per-viewer identity, no geographic/device breakdown).
- Product variant support (size/color) — CLAUDE.md's product model is a subscription package, not a physical SKU with variants; not requested.

## Addendum — share-link revoke scope (human-confirmed 2026-07-16)

Frontend build-out surfaced an authorization question not addressed in the original decisions: `DELETE /share-links/{id}` authorizes via `can('view', $salesMaterial->product)`, meaning any same-company user who can view the product — not just the link's own creator — can revoke it (e.g. one agent can revoke a link another agent minted). Flagged to the human per Guardrail 6/7 rather than assumed. **Decision: keep as-is** — team-wide revoke access matches how this team already works together; no backend change needed. Both frontends' unconditional revoke button is therefore correct as built.
