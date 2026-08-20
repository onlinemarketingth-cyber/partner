# TASK-204 — name-first brand/category list + company tick boxes on edit

- **Owner:** ag-ui (frontend-admin only) → ag-qa
- **Date:** 2026-08-19
- **Status:** implemented in session, pending ag-qa
- **Human (verbatim):**
  1. "ui list แสดงแบบนี้สับสนได้ง่าย ให้ปรับเป็นชื่อ...นำ และมีชื่อบริษัทอยู่ใต้ชื่อแบรนด์ —
     ตัวอย่าง: `Brand 1` / `company1 company2 company3`"
  2. "แก้ edit ทั้งแบรนด์และหมวดหมู่ให้มีการเลือกบริษัทแบบเดียวกับการเพิ่ม"
- **Related:** TASK-202 (company-per-row clarity — superseded here), TASK-203 (the
  `CompanyMultiSelect` this reuses), BR-6 / Section 5 (rows stay company-scoped; only the
  presentation is name-first).

---

## 1. The list is now grouped by NAME

TASK-202 grouped by company (company heading → its rows). The human's verdict after using it was
that it still reads as duplicates: the same "sss" appears three times under three headings.

So the unit on screen is the **name**: one card per distinct trimmed name, with **one chip per
company** that has it, chip highlighted when it matches the scope picker and suffixed "(ปิด)" when
that company's row is deactivated. Right-hand side shows `ใช้กับสินค้า N` (summed across
companies) and a status that can now be mixed: **ใช้งาน / ปิดใช้งาน / ใช้งาน 2/3 บริษัท**.

Underneath nothing changed: three companies with "sss" are still three `brands` rows with three
`company_id`s (BR-6). Only the rendering collapses them.

The scope picker still filters (only names present in the chosen company are listed) but the chips
always show **every** company holding that name — hiding them would recreate the "why is this name
here twice" confusion.

## 2. Edit is name-level, with the same tick-box picker as create

`CompanyMultiSelect` (TASK-203) now appears in the edit form for both brands and categories,
pre-ticked with the companies that currently have the name. Saving reconciles the ticked set:

| state | action |
|---|---|
| ticked & row exists | `PUT` — rename / activate / (categories) icon + sort_order |
| ticked & no row | `POST` — add this brand/category to that company |
| unticked & row exists | `DELETE` — remove it from that company (soft delete) |

Every step reports **per company** on failure (`Thai Life: ลบไม่ได้ เพราะยังมีข้อมูลอ้างอิงอยู่...`),
and the list reloads regardless so partial results are visible. Unticking every company is refused
in-form ("ต้องเหลืออย่างน้อย 1 บริษัท — ถ้าต้องการเอาออกทุกบริษัท ให้ใช้ปุ่มลบ") because that is a
delete, and deletes go through the confirm dialog.

Delete (the bin) is name-level too and the confirm dialog now names the companies it will hide the
row from.

## 3. Deliberate trade-offs — flag if wrong

- **Rename applies to every ticked company.** Renaming "sss" → "SSS" while three companies are
  ticked renames all three. Renaming in only one company = untick the others first, then create
  the new name separately. This follows from name-first grouping; the alternative (per-company
  rename) is what the human just rejected.
- **Categories: icon and sort_order are per row but edited as one.** The form takes the first
  row's values and writes them to every ticked company. In practice the same category name carries
  the same icon everywhere; if that stops being true, this needs a rethink.
- **Not atomic** — same as TASK-203's fan-out. Failures are reported per company rather than
  rolled back.

## 4. Acceptance criteria

- [x] List shows name first with company chips underneath (human's sketch)
- [x] Chip highlights the scoped company, marks per-company deactivation
- [x] Edit form has the same company tick-box picker as create, pre-ticked
- [x] Ticking adds, unticking removes, renaming applies across ticked companies
- [x] Per-company failure reporting; list reloads either way
- [x] Delete dialog names the affected companies
- [x] SFC compiles clean (0 template errors)
- [ ] ag-qa: no cross-tenant write — every POST/PUT/DELETE in the fan-out still passes
      Policy + TenantScope; Company Admin sees no picker and can only touch their own rows
- [ ] UAT in the browser
