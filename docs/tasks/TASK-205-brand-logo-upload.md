# TASK-205 — brand logo upload (brands only)

- **Owner:** ag-dev (backend) → ag-ui (frontend-admin) → ag-qa
- **Date:** 2026-08-19
- **Status:** implemented in session, pending ag-qa
- **Human:** "ผมต้องการเฉพาะแบรนด์มีการ upload รูปแบรนด์ได้" — brands get an image upload;
  categories keep their existing curated-icon picker (TASK-068 / ADR-020 row 3), untouched.
- **Related:** TASK-068 / ADR-020 row 2 (`StorefrontBannerService` — the image-upload pattern
  copied here verbatim), TASK-202/203/204 (the dialog this lands in), Section 6 (upload
  validation), Section 5 rule 6 (why this is NOT an access-checked private file).

---

## 1. Backend

`brands.logo_path` already existed in the schema and `BrandResource` already returned it — nothing
ever wrote it. Now:

| File | Change |
|---|---|
| `StoreBrandRequest` / `UpdateBrandRequest` | `logo` — `file, image, mimes:jpeg,jpg,png,webp, max:2048`; update also accepts `remove_logo` (boolean) |
| `BrandController::store/update` | pass `$request->file('logo')` to the Service (same shape as `StorefrontBannerController`) |
| `BrandService` | `applyLogo()` / `deleteFile()` on the `public` disk, uuid filename, old file deleted on replace |
| `BrandResource` | `logo_url` — resolved public-disk URL, `null` when unset |

Decisions worth stating:

- **`public` disk, not an access-checked stream.** A brand mark is marketing artwork every agent
  in the company is meant to see — the same call already made for storefront banners and
  announcements. Section 5 rule 6's tenant-scoped-path rule is about client documents (PDPA).
- **SVG is rejected.** An SVG is an executable document (`<script>`, `foreignObject`) and these
  files are served straight off the public disk — accepting one is stored XSS (Section 6).
- **2 MB**, not the banner's 5: a logo renders as a ~40px mark in a list, never full-bleed.
- **Absent `logo` means "leave it alone".** Otherwise every rename would silently wipe the mark.
  Clearing needs the explicit `remove_logo` flag, which the UI exposes as a checkbox.
- **uuid filename, never the client's.** The original name is attacker-controlled (traversal,
  cross-company collisions).

Tests added to `BrandTest` (all green): upload sets `logo_path` + `logo_url` and the file exists;
replacing deletes the previous file; a plain rename keeps the logo while `remove_logo` clears it
and removes the file; an SVG and a PDF are both rejected 422.

## 2. Frontend (`ProductCatalogView.vue`)

- Brand **create** form: file picker + 56px preview + "เอารูปออก", client-side compression through
  the existing `compressImageToFit` helper (same pipeline as the banner uploader), and a refusal
  message if the image is still over 2 MB after compression.
- Brand **edit** form: shows the current logo, lets you replace it, and offers **"ลบรูปโลโก้ออก"**
  (sends `remove_logo`). Picking a replacement cancels a pending removal.
- Brand rows now **lead with the logo** (40px, `object-contain`) falling back to the tag icon.
- Creates and updates now go out as **multipart** (`api.postForm`, `_method=PUT` on update) since
  browsers cannot send multipart on a real PUT — same approach as the banner form on this screen.
- The multi-company fan-out (TASK-203/204) uploads the file **once per company row**, because each
  company's brand is its own row with its own `logo_path`.

## 3. Acceptance criteria

- [x] Upload a logo when creating a brand; visible in the list immediately
- [x] Replace / clear a logo when editing; replacing removes the old file from disk
- [x] Rename never wipes the logo
- [x] Only jpeg/png/webp accepted, ≤2 MB after client-side compression; SVG and PDF rejected 422
- [x] Categories unchanged (icon picker only)
- [x] Pint clean on every touched file; backend suite 1595 passed
- [x] SFC compiles clean (0 template errors)
- [ ] ag-qa: a Company Admin cannot upload onto another company's brand (Policy + TenantScope
      unchanged, but the endpoint now takes a file — re-verify), and `storage:link` exists on the
      target environment so `logo_url` actually resolves
- [ ] UAT in the browser

## 4. Deployment note

`logo_url` points at the `public` disk, so the environment needs `php artisan storage:link`
(already required by banners/announcements — if those images render today, nothing to do).
