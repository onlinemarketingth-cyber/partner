# TASK-203 — create a brand/category in several companies at once (tick-box picker)

- **Owner:** ag-ui (frontend-admin only) → ag-qa
- **Date:** 2026-08-19
- **Status:** implemented in session, pending ag-qa
- **Human (verbatim intent):**
  1. "ในตอนเพิ่มแบรนด์ หรือ หมวดหมู่ ให้มีช่องในการเลือก...เป็นแบบ dropdown list แล้วติ๊ก All
     หรือ clear all และติ๊กเลือกบริษัทได้"
  2. "ตรงทุก list เลย คำว่า 1 แบรนด์ 1 หมวดหมู่ ไม่มีประโยชน์อะไรเลย เอาออก"
- **Related:** TASK-202 (the company-clarity pass this refines), BR-6 / Section 5 (brands and
  categories are per-company rows — the reason "one brand in three companies" is three rows).

---

## 1. What changed

**New component — `frontend-admin/src/design-system/components/CompanyMultiSelect.vue`**

A tick-box dropdown: trigger shows a summary (`GENESENN Health` / `3 บริษัท` / `ทุกบริษัท (3)`),
panel has **เลือกทั้งหมด / ล้างทั้งหมด**, a `เลือกแล้ว N/M` counter, and one checkbox row per
company. Closes on click-outside and Esc, matching every other popover in the design system.

Deliberately not a native `<select multiple>`: that control needs cmd/ctrl-click to add a second
option and wipes the selection on a plain click — the exact "เข้าใจยาก" failure this task removes.

**`ProductCatalogView.vue`**

- Both create forms (brand, category) now carry their own `CompanyMultiSelect`, independent of the
  dialog's list-scope picker. Opening a form **pre-ticks** the currently scoped company (if any),
  every tick still editable.
- Submit **fans out one POST per ticked company** — `BrandService::create()` /
  `ProductCategoryService::create()` stamp exactly one `company_id` each, so the loop lives
  client-side rather than inventing an array endpoint.
- **Partial failure is reported as partial**: `บันทึกสำเร็จ 4 จาก 5 บริษัท — ที่ไม่สำเร็จ: <company>: <reason>`,
  and the list reloads either way so rows that did save are visible. Reporting a partial success as
  a flat failure would make the admin re-create duplicates.
- The create button is no longer disabled by the top scope picker (the form owns the choice now),
  and the amber "เลือกบริษัทด้านบนก่อน" hint is gone. Validation moved into the form: **"ติ๊กเลือก
  อย่างน้อย 1 บริษัทก่อนบันทึก"**.
- Per-group row counts ("1 แบรนด์" / "1 หมวดหมู่") removed from both tabs.

## 2. Not changed

- No backend change at all. No new endpoint, no bulk-create route, no validation change.
- Company Admin sees no company picker (their `company_id` is inferred server-side) — one POST,
  exactly as before.
- Tenancy/permission rules untouched: each POST is the same single-company create the Policy and
  Service already gate.

## 3. Acceptance criteria

- [x] Create form has a tick-box company dropdown with เลือกทั้งหมด / ล้างทั้งหมด
- [x] Ticking N companies creates the row in all N
- [x] Saving with nothing ticked is blocked in-form with a clear message
- [x] Partial failure names which companies failed and why; successful rows still appear
- [x] "N แบรนด์ / N หมวดหมู่" text removed from every group header
- [x] Both SFCs compile clean (`@vue/compiler-sfc`, 0 template errors)
- [ ] ag-qa: Company Admin still sees no picker and can create only in their own company;
      a Super Admin's fan-out lands each row under the right `company_id` (no cross-tenant leak)
- [ ] UAT in the browser (not yet clicked through by ag-lead)

## 4. Known trade-off

The fan-out is **not atomic**: 5 ticked companies = 5 requests, and a failure on #3 leaves #1–2
created. This is reported honestly rather than hidden, and re-submitting with only the failed
companies ticked is the recovery path. A transactional bulk endpoint would be a backend change
(new route + request + service) — out of scope here; raise it if partial creates become a real
operational problem.
