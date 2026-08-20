# TASK-221 — UI จัดการระดับใบรับรอง (Cert Tier)

**Status:** implemented · 1660 tests passed · **Owner:** ag-lead
**คำขอ:** human — "ui เพิ่ม Tier อยู่ที่ไหน" (ฟอร์มเพิ่ม Section บันทึกไม่ได้ เพราะ dropdown Cert tier ว่าง)

---

## 1. คำตอบสั้น ๆ — เดิม **ไม่มี**

`routes/api.php` มีบรรทัดเดียว:

```php
Route::get('/cert-tiers', [CertTierController::class, 'index']);
```

**อ่านอย่างเดียว** ไม่มี POST/PUT/DELETE และไม่มีหน้าจอไหนสร้าง tier ได้เลย
tier ถูกสร้างโดย `CatalogSeeder` เท่านั้น ซึ่งเป็น **dev-only seeder** (พ่วง brand /
product / commission rule ปลอมมาด้วย) จึงไม่เคยรันบน production

**ยืนยันจาก production 2026-08-20:** `GET /cert-tiers` → `200 {"data":[]}`

อาการที่ human เจอจึงอยู่ห่างจากต้นเหตุ 2 หน้าจอ: ฟอร์ม Section บันทึกไม่ได้
เพราะ `<select>` Cert tier เป็น required แต่ไม่มีตัวเลือกให้เลือก

## 2. เจอบั๊ก 500 ซ้อนอยู่ด้วย (regression จาก TASK-209)

```
GET /cert-tiers?company_id=1  →  500 Server Error
```

`CertTierController` เรียก `CompanyScopeFilter::apply()` พร้อมคอมเมนต์ว่า
*"cert tiers are per-company (cert_tiers.company_id)"* — **แต่ตารางไม่มีคอลัมน์นั้น**
migration เขียนไว้ตรง ๆ ว่า *"Global / platform-wide (no company_id)"*

filter จึงต่อ `where company_id = ?` เข้าไปกับตารางที่ไม่มีคอลัมน์ → SQL error

ไม่เคยระเบิดเพราะ**ไม่มีหน้าจอไหนส่ง `company_id` มาที่ endpoint นี้** — กับระเบิด
ไม่ใช่ฟีเจอร์ที่ทำงานอยู่ · ผมไล่เช็คทั้ง 20+ controller ที่เรียก `CompanyScopeFilter`
แล้ว **`cert_tiers` เป็นตารางเดียวที่ไม่มี `company_id`**

---

## 3. สิ่งที่ทำ

### หน้าจอใหม่ — Academy → แท็บ **"ระดับใบรับรอง"**

| | |
|---|---|
| ตำแหน่ง | แท็บที่ 5 ของหน้า Academy · **Super Admin เห็นคนเดียว** |
| ทำอะไรได้ | เพิ่ม / แก้ชื่อ / เรียงลำดับ / ตั้งว่าเป็นระดับบังคับ / ลบ |
| ป้ายเตือนบนหน้าจอ | "ใช้ร่วมกันทุกบริษัท — แก้ที่นี่มีผลกับทุกบริษัทในระบบ" |

**ทำไมเป็นแท็บ ไม่ใช่หน้าใหม่:** เหตุผลเดียวกับคลังแบบทดสอบ (ADR-030) — เป็น
config ของ Academy ที่มีความหมายเฉพาะเมื่ออยู่ข้าง Section/แบบทดสอบที่อ้างถึงมัน

**ทำไมอยู่ท้ายสุด** ทั้งที่ไม่มีมันแล้วอย่างอื่นทำไม่ได้: ตั้งครั้งเดียวแล้วแทบไม่แตะอีก ·
แท็บที่ต้องกดข้ามทุกวันเพื่อไปแท็บที่ต้องการ คือภาษีที่เก็บจากทุกครั้งที่เข้าหน้านี้ ·
หน้าที่บอกทางคือ empty state ของแท็บที่ต้องใช้ tier

### ทำไม Super Admin เท่านั้น

`cert_tiers` **ไม่มี `company_id`** → ทุกบริษัทใช้ list เดียวกัน
Company Admin เปลี่ยนชื่อ "Basic" = เปลี่ยนให้ทุก tenant ในระบบ และการลบ tier
จะเอื้อมไปถึง commission rule / โมดูล / การรับรองของบริษัทอื่น

เหตุผลเดียวกับชุดสีกลาง (TASK-217) และ SMTP กลาง (TASK-190)
· **อ่าน** ยังเปิดให้ทุก role รวมถึง Agent เหมือนเดิม (แอปตัวแทนใช้แสดงความคืบหน้า)

### กฎที่บังคับไว้

| กฎ | ทำไม |
|---|---|
| **ลบ tier ที่มีข้อมูลผูกอยู่ไม่ได้** | 11 ตารางชี้มาที่นี่ด้วย `restrictOnDelete` · ถ้าปล่อยให้ DB ปฏิเสธเอง จะได้ 500 พร้อม SQLSTATE — service จึงนับให้ก่อนแล้วตอบเป็น**ประโยคที่บอกว่าติดอะไรอยู่กี่รายการ** |
| **เปลี่ยน `key` ไม่ได้ถ้ามีข้อมูลผูกแล้ว** | `key` คือ handle ที่โค้ดฝั่งเซิร์ฟเวอร์ match (`where('key','basic')`) · ย้ายใต้ข้อมูลจริง = พังเงียบ ๆ ในที่ที่มองจากตารางนี้ไม่เห็น · **ชื่อที่แสดงเปลี่ยนได้เสมอ** |
| **`sort_order` ว่าง = ต่อท้ายอัตโนมัติ** | ไม่ default เป็น 0 · tier สองตัวที่ sort_order เท่ากันทำให้ query "ระดับสูงสุดที่ผ่าน" เรียงมั่ว ซึ่งออกมาเป็น**ค่าคอมผิด** ไม่ใช่ลิสต์ผิด |
| **`key` รับแค่ `a-z0-9_`** | เป็น input จาก client ที่ไปโผล่ใน URL และ query |

### BR-7 — ไม่มีค่า tier ไหนถูกใส่ให้

ไม่มี default ของ `key` / `name` / `is_mandatory` ทั้งใน service, form request หรือ
หน้าจอ · `CLAUDE.md §2` เขียนโครงไว้ว่า **Basic (บังคับ) → Intermediate → High**
ซึ่ง empty state **อ้างถึงเป็นคำใบ้** แต่ไม่กรอกให้

---

## 4. ไฟล์

| ไฟล์ | |
|---|---|
| `backend/app/Services/Academy/CertTierService.php` | **ใหม่** — create/update/delete + guard ทั้งสอง + `usageSummary()` |
| `backend/app/Policies/CertTierPolicy.php` | **ใหม่** — อ่านเปิดหมด เขียน Super Admin |
| `backend/app/Http/Requests/Academy/StoreCertTierRequest.php` | **ใหม่** |
| `backend/app/Http/Requests/Academy/UpdateCertTierRequest.php` | **ใหม่** |
| `backend/app/Http/Controllers/Api/V1/CertTierController.php` | เพิ่ม store/update/destroy · **เอา CompanyScopeFilter ออก** |
| `backend/routes/api.php` | `Route::get` → `apiResource(...)->except('show')` |
| `backend/tests/Feature/Academy/CertTierManagementTest.php` | **ใหม่** — 13 test |
| `frontend-admin/src/views/CertTierPanel.vue` | **ใหม่** |
| `frontend-admin/src/views/AcademyManagementView.vue` | แท็บที่ 5 + `tabs` computed กรอง superAdminOnly |

## 5. ตรวจแล้ว

- `php artisan test` → **1660 passed (6215 assertions)** — เดิม 1647, ใหม่ +13
- `pint` สะอาด · `vue-tsc` + `eslint` สะอาด · SFC ทั้งสองไฟล์ compile ผ่าน
- **ยังไม่ได้กดจริงในเบราว์เซอร์** — dev server ฝั่ง admin ติด `pdfjs-dist → 503`
  (Vite dep optimizer ค้าง ไม่เกี่ยวกับงานนี้) ดูวิธีแก้ข้อ 6

## 6. ก่อนทดสอบ — ล้าง cache ของ Vite

```bash
cd frontend-admin
# Ctrl+C หยุด dev server
rm -rf node_modules/.vite
npm run dev
```

แล้ว hard refresh (`Cmd+Shift+R`) · อาการ 503/504 ของ `node_modules/.vite/deps/*`
คืออาการเดียวกับที่เจอตอนต้นวัน ไม่ใช่บั๊กของโค้ด

## 7. หลัง deploy — production ต้องสร้าง tier ก่อนใช้ Academy

Super Admin → **Academy → ระดับใบรับรอง → + เพิ่มระดับ**

โครงที่ระบบออกแบบไว้ (`CLAUDE.md §2`): `basic` บังคับ → `intermediate` → `high`
· ชื่อที่แสดงตั้งเป็นภาษาไทยได้ตามต้องการ
