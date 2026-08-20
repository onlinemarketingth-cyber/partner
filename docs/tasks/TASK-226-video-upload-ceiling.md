# TASK-226 — ตั้งค่าขนาดวิดีโอแล้วยังติด 200 MB เหมือนเดิม

**Status:** implemented · backend 1665 passed / 6231 assertions · frontend-admin 106 passed · **Owner:** ag-lead
**คำขอ:** human — *"ใน production ผมเพิ่ม Setting วิดิโอแล้วยัง Fix อยู่ที่ 200 mb แก้ไขให้ตาม setting"*

---

## 1. อาการ

ตั้ง **ขนาดไฟล์สูงสุด = 300 MB** ให้บริษัท "ไทยประกันชีวิต" เรียบร้อย บันทึกแล้วค่าอยู่จริง
แต่พออัปโหลดคลิปใหญ่กว่า 200 MB ยังถูกปฏิเสธด้วยข้อความเดิม:

> ไฟล์ใหญ่เกินขนาดที่บริษัทกำหนด (200 MB)

200 คือ **ค่า default ของแพลตฟอร์ม** ไม่ใช่ค่าที่ตั้งไว้ — แปลว่าเซิร์ฟเวอร์ไม่เคยอ่านแถวของบริษัทนี้เลย

## 2. สาเหตุ — TASK-222 แก้ให้ไม่ 500 แต่ยังตอบผิดค่า

ตรวจบน production ก่อน ไม่เดา:

| ตรวจ | ผล |
|---|---|
| `role` ของบัญชีที่ใช้ | `super_admin` |
| `max_upload_mb` ของไทยประกันชีวิต | **300** (บันทึกสำเร็จจริง) |
| `/cert-tiers?company_id=1` | 200 → ยืนยันว่าโค้ดชุด TASK-221/222 ขึ้น production แล้ว |

`users.company_id` ของ Super Admin เป็น **NULL** โดยตั้งใจ (เขาไม่สังกัดบริษัทใด)
TASK-222 แก้ TypeError ด้วยการทำให้ `VideoProcessingSettingService::forCompany(?int)` รับ null ได้
และเมื่อรับ null มันคืน **ค่า default ของแพลตฟอร์ม** — ซึ่งถูกต้องในฐานะ fallback
แต่ `ChunkedUploadController::init()` ส่ง `$user->company_id` เข้าไปตรง ๆ เสมอ

> Super Admin → null → default 200 **ทุกครั้ง** ต่อให้เลือกบริษัทไว้บนหัวหน้าจอแล้วก็ตาม

บั๊กนี้จึงมองไม่เห็นสำหรับ Company Admin (`company_id` ของเขาไม่เคยเป็น null)
และเห็นเฉพาะ Super Admin เท่านั้น — ตรงกับที่ human เจอพอดี

## 3. การแก้ — ให้ client บอกว่า "ตอนนี้กำลังทำงานให้บริษัทไหน"

ข้อมูลนี้มีอยู่แล้วในตัวเลือกบริษัทบนหัวหน้าจอ admin (ADR-038 / TASK-209)
แต่ไม่เคยถูกส่งไปกับ `/uploads/init` เพราะ endpoint นี้เกิดก่อน active-company store

### 3.1 Backend — `ChunkedUploadController`

```php
'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
```

```php
private function resolveCompanyId(Request $request, User $user): ?int
{
    if (! $user->isSuperAdmin()) {
        return $user->company_id;
    }

    return $request->filled('company_id') ? $request->integer('company_id') : null;
}
```

`$companyId` ที่ได้ถูกใช้ **ทั้งสองที่** — คำนวณ `$maxBytes` และเขียนลงแถว `chunked_uploads`
ให้เพดานกับเจ้าของไฟล์เป็นบริษัทเดียวกันเสมอ ไม่มีทางหลุดคนละบริษัท

**สามข้อตัดสินใจที่ตั้งใจ:**

1. **Company Admin / Agent ส่ง `company_id` มาก็ถูกทิ้งทั้งหมด** — ไม่ใช่ 403 แต่ "ไม่สนใจ"
   ถ้าเชื่อค่าที่ส่งมา บริษัทหนึ่งจะยืมเพดานที่ใหญ่กว่าของอีกบริษัทได้ทันที ผิด **BR-6**
   และเขาไม่มีสิทธิ์เลือกอยู่แล้ว การ 403 จึงเป็นการลงโทษสิ่งที่ไม่มีผลอะไร
2. **`sometimes` ไม่ใช่ `required`** — "ทุกบริษัท" เป็นสถานะจริงของตัวเลือกบนหัวจอ
   การห้ามอัปโหลดในสถานะนั้นแย่กว่าการ fallback ไปที่ default ของแพลตฟอร์ม
3. **`exists:companies,id`** — กัน id ที่พิมพ์ผิดไม่ให้เงียบ ๆ กลายเป็น "ไม่เลือกอะไรเลย"

### 3.2 Frontend — `utils/activeCompanyStorage.ts` (ไฟล์ใหม่)

`api/client.ts` เรียก `useActiveCompanyStore()` ตรง ๆ ไม่ได้ เพราะ **store import `@/api/client`**
(มันไปดึงรายชื่อบริษัท) — import กลับจะเป็น circular import ชนิดที่ resolve เป็น `undefined`
ตอน module-evaluation และพังเฉพาะใน production build เท่านั้น

จึงแยก leaf module ที่ไม่ import อะไรของแอปเลย ทั้งสองฝั่งพึ่งพาได้ปลอดภัย:

```ts
export const ACTIVE_COMPANY_STORAGE_KEY = 'sva.admin.activeCompanyId'
export function readPersistedActiveCompanyId(): number | null
```

**มีแต่ตัวอ่าน ไม่มีตัวเขียน โดยตั้งใจ** — store ยังเป็นเจ้าของค่าและกฎทั้งหมดแต่ผู้เดียว
มีที่เดียวในแอปที่เปลี่ยนการเลือกบริษัทได้ ไฟล์นี้รู้แค่ "คีย์อยู่ที่ไหน อ่านกลับยังไง"
`'all'` และค่าที่อ่านไม่ออกทั้งหมดถูกแปลงเป็น `null` และ `try/catch` ครอบไว้เพราะ
Safari โหมดส่วนตัวโยน exception ตั้งแต่ตอนอ่าน property

`stores/activeCompany.ts` เปลี่ยนมา import ตัวเดียวกันนี้แทนสำเนา `readPersisted()` ของตัวเอง
— นิยามของ "อ่านค่าที่เก็บไว้" จึงมีชุดเดียวจริง ๆ ไม่ใช่สองชุดที่รอ drift

`uploadInChunks()` ส่ง `company_id` ไปกับ `/uploads/init` และ **ไม่ส่ง key เลย** เมื่อเป็น null
(ไม่ใช่ส่ง `null`) เพื่อให้ `sometimes` ของฝั่ง server ทำงานตามที่ออกแบบ

## 4. เทสต์ที่เพิ่ม — `ChunkedUploadTest` (+3, รวมเป็น 10)

| เทสต์ | พิสูจน์อะไร |
|---|---|
| `a super admin gets the named company's ceiling not the platform default` | **เคสของ human ตรง ๆ** — บริษัทตั้ง 300 MB, Super Admin ระบุบริษัทนั้น, ไฟล์ 250 MB ต้องผ่าน |
| `a super admin who names no company still gets the platform default` | "ทุกบริษัท" ยังอัปโหลดได้ ไม่ใช่ 422 |
| `a company admin's supplied company_id is ignored` | BR-6 — ส่ง id ของบริษัทที่เพดานใหญ่กว่ามา ก็ยังโดนเพดานของตัวเอง |

เทสต์ตัวแรกล้มด้วยข้อความ "เกิน 200 MB" กับโค้ดเดิม และผ่านกับโค้ดใหม่ — ยืนยันว่ามันจับบั๊กจริง

## 5. ตรวจแล้ว

- backend: `php artisan test` → **1665 passed (6231 assertions)**; `pint` สะอาดในไฟล์ที่แตะ
- frontend-admin: `vitest` → **106 passed (9 files)**; `vue-tsc` และ `eslint` ผ่าน

## 6. สิ่งที่ยังไม่ได้แก้ (ตั้งใจ)

- **ฝั่ง agent portal (`frontend/`) ไม่แตะ** — Agent สังกัดบริษัทเสมอ `company_id` ไม่มีทางเป็น null
  การใส่ logic เดียวกันที่นั่นคือโค้ดที่ไม่มีวันทำงาน
- **ลิมิตของ PHP/host ไม่เกี่ยวและไม่ต้องแก้** — `upload_max_filesize` บนเซิร์ฟเวอร์คือ 2048M
  และระบบส่งเป็น chunk ละ ~5 MB อยู่แล้วตั้งแต่ TASK-094
- **`max_upload_mb` สูงสุดที่ฟอร์มยอมให้กรอกคือ 2000** — ยังไม่ยกเพราะยังไม่มีเหตุผลทางธุรกิจ (BR-7)
