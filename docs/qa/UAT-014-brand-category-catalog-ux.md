# UAT-014 — brand/category dialog, multi-company create, brand logo, catalog link

- **Covers:** TASK-202, TASK-203, TASK-204, TASK-205, TASK-206 (+ the migration-drift fix)
- **Date:** 2026-08-19
- **Tester:** human (KreangYot) — first browser pass; ag-lead has verified compile + backend suite
  only (1599 passed), **no click-through has been done yet**
- **Login as:** Super Admin (steps 6–7 also need a Company Admin login)

---

## 0. Pre-flight

| # | Command / check | Expected |
|---|---|---|
| 0.1 | `cd backend && php artisan migrate:status \| grep -i pending` | no output (nothing pending) |
| 0.2 | `php artisan storage:link` | "link has been created" or "already exists" |
| 0.3 | `php artisan serve --host=admin.localhost --port=8010` | serving — **leave this terminal open** |
| 0.4 | `cd frontend-admin && npm run dev` | Vite up, no red errors — **leave open** |
| 0.5 | Open the admin URL, DevTools → Console | no `ERR_CONNECTION_REFUSED` on `/api/v1/me` |

If 0.5 fails nothing else in this document will work — the app cannot reach Laravel.

---

## 1. Dialog opens as a modal (TASK-204)

1. Go to **สินค้า** → click **จัดการแบรนด์ / หมวดหมู่**
2. Expect: a centred white dialog over a dimmed page (NOT a full-screen white page)
3. Press `Esc` → closes. Reopen, click the dark backdrop → closes. Reopen, click ✕ → closes

## 2. Company scope + name-first list (TASK-202 / 204)

1. Reopen the dialog. Expect a blue-tinted **บริษัท** bar **above** the tabs
2. Default is **ทุกบริษัท (จัดกลุ่มให้)**; the badge on each tab is a blue circle with white text
3. Expect the list to show **one card per brand NAME**, with the company chips underneath it —
   e.g. `sss` with `GENESENN Health` `QA Test Co` `Thai Life`
4. Right of each card: `ใช้กับสินค้า N` and a status (`ใช้งาน` / `ปิดใช้งาน` / `ใช้งาน 2/3 บริษัท`)
5. Pick **GENESENN Health** in the บริษัท bar → the list narrows to names that company has; its
   chip turns solid blue on each row
6. Type in **ค้นหา** → the list filters live; clearing restores it

## 3. Create a brand in several companies at once (TASK-203) + logo (TASK-205)

1. Click **+ เพิ่มแบรนด์**
2. In **สร้างในบริษัท**, click the picker → tick two companies (try **เลือกทั้งหมด** then
   **ล้างทั้งหมด** first — the counter `เลือกแล้ว N/M` should track)
3. Name it `UAT Logo Brand`
4. Under **โลโก้แบรนด์**, choose a JPG/PNG → a 56px preview appears
5. Save. Expect: form closes, and **one card** named `UAT Logo Brand` appears with **two company
   chips** and the logo shown at the left of the row
6. Re-open the dialog and confirm the logo persists (it is on disk, not just in the browser)

**Negative:** try saving with **no company ticked** → blocked in-form with
"ติ๊กเลือกอย่างน้อย 1 บริษัทก่อนบันทึก" (no request sent)
**Negative:** try uploading a `.svg` or a `.pdf` → rejected

## 4. Edit across companies (TASK-204)

1. Click ✎ on `UAT Logo Brand`
2. Expect: caption `กำลังแก้ไข · UAT Logo Brand`, a company picker **pre-ticked with its 2
   companies**, the current logo, and a **ลบรูปโลโก้ออก** checkbox
3. Rename to `UAT Logo Brand v2`, tick a **third** company, save
4. Expect: one card, **three** chips, new name everywhere
5. Edit again, **untick** one company, save → that company's chip disappears
   (if that company's brand is used by products, expect a per-company message such as
   `Thai Life: ลบไม่ได้ เพราะยังมีข้อมูลอ้างอิงอยู่: สินค้า N รายการ` — that is correct behaviour)
6. Edit again, tick **ลบรูปโลโก้ออก**, save → the logo disappears from the row

**Negative:** untick every company → blocked: "ต้องเหลืออย่างน้อย 1 บริษัท…"

## 5. Categories behave identically (minus the logo)

Repeat §3 and §4 on the **หมวดหมู่** tab. The company picker must appear in both create and edit;
there is **no** logo upload here — the icon picker stays.

## 6. Company Admin sees the simple version

1. Log in as a **Company Admin**
2. Open the dialog → expect **no บริษัท bar**, **no company chips**, **no company picker** in the
   forms (they only ever have one company)
3. Create a brand → it must land in their own company

## 7. Tenant isolation spot-check (Section 5 / BR-6)

1. As Company Admin, open DevTools → Network, note a brand id from **another** company (from the
   Super Admin session)
2. `PUT /api/v1/brands/<that id>` by hand → expect **404** (not 200, not 500)

## 8. Catalog link keeps commission working (TASK-206)

1. As Super Admin: **สินค้า → แคตตาล็อกกลาง** → create a brand, a category, then a
   **รายการแคตตาล็อก**
2. Open a product → tab **ทั่วไป** → the gold button **🌐 เชื่อมกับแคตตาล็อกกลาง** → pick the item
3. Expect the name/brand/category area to become a grey read-only box with
   "ไปที่แคตตาล็อกกลาง →" and "ยกเลิกการเชื่อม"
4. **The point of this step:** run
   `php artisan catalog:backfill-linked-taxonomy --dry-run` → must report
   **"No catalog-linked product is missing its brand/category"**, proving the link kept the
   product's own brand/category (if it lists the product you just linked, the fix did not apply —
   report it)
5. Filter the product list by that brand → the linked product must still be found

---

## Reporting

For anything that fails, capture: the step number, a screenshot, and the DevTools **Console** +
**Network** tab for the failing request. A 422 body carries the Thai reason and is the most useful
single thing to send.
