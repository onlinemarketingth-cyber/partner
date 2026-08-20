# TASK-222 — Super Admin อัปโหลดไฟล์ใหญ่ไม่ได้ (500 ที่ /uploads/init)

**Status:** implemented · 1662 tests passed · **Owner:** ag-lead
**คำขอ:** human — "upload คลิป 198 mb ใน Production แล้ว error ตรวจสอบเพราะลิมิตของ Host หรือไม่"

---

## 1. คำตอบ: **ไม่ใช่ลิมิตของ Host**

ผมยิง endpoint เดียวกันบน local (APP_DEBUG เปิด) ได้ stack trace เต็ม ๆ:

```
TypeError: VideoProcessingSettingService::forCompany():
  Argument #1 ($companyId) must be of type int, null given,
  called in ChunkedUploadController.php on line 67
```

**`users.company_id` ของ Super Admin เป็น NULL** — เขียนไว้ตั้งใจใน migration:

> *"Nullable: Super Admin is not scoped to any single company (Section 5, rule 4) …
> Super Admin is the one legitimate exception to NOT NULL."*

`ChunkedUploadController::init()` ส่ง null นั้นเข้าไปใน parameter ที่ประกาศเป็น `int`
→ **TypeError → 500 ตั้งแต่ก่อนส่งไบต์แรก**

### หลักฐานว่าไม่ใช่ Host

| ตรวจ | ค่า |
|---|---|
| `upload_max_filesize` / `post_max_size` บนเซิร์ฟเวอร์ | **2048M** |
| ขนาดที่ส่งจริงต่อ request | **5 MB** (ระบบส่งเป็น chunk อยู่แล้วตั้งแต่ TASK-094) |
| endpoint ที่พัง | `/uploads/init` ซึ่ง**ยังไม่ได้ส่งไฟล์เลย** ส่งแค่ชื่อ/ขนาด |

ระบบ chunked upload ถูกสร้างมาเพื่อไม่ต้องแตะลิมิต PHP ของ production อยู่แล้ว
(เหตุผลเดิม: *"ถ้าไปปรับขนาดจะมีปัญหากับ production"*) — ลิมิต Host จึงไม่มีทางเป็นสาเหตุ

## 2. บั๊กนี้ถูกบันทึกไว้ในเทสต์ตั้งแต่ TASK-185 แต่ไม่มีใครแก้

`RoleGateCharacterizationTest` มีบรรทัดนี้อยู่:

```php
// TODO: CONFIRM (behaviour recorded, not endorsed) — a Super Admin
// with NO ?company_id gets a 500 … Recorded, NOT fixed (TASK-185 §4).
'settings.video_processing.view' => [403, 200, 500, 200],
                                          ↑ 500 ถูกบันทึกไว้เป็น "พฤติกรรมที่คาดหวัง"
```

คือ null ตัวเดียวกันเป๊ะ แต่เข้ามาทาง endpoint อื่น · **ถูกบันทึกว่าพัง แล้วปล่อยไว้**
จนกระทั่งมันโผล่อีกประตูหนึ่งและไปโดน human บน production

หลังแก้ บรรทัดนั้นเปลี่ยนเป็น `[403, 200, 200, 200]` — **characterization test ทำงานถูกหน้าที่**

## 3. แก้อย่างไร

| ไฟล์ | เปลี่ยน |
|---|---|
| `VideoProcessingSettingService::forCompany()` | `int` → **`?int`** · null = ไม่มีบริษัท จึงไม่มี override → คืนค่า default ของแพลตฟอร์ม ซึ่งเป็นคำตอบเดียวกับบริษัทที่ไม่เคยตั้งค่าเอง |
| `2026_09_06_090000_make_chunked_uploads_company_id_nullable.php` | **ใหม่** — `chunked_uploads.company_id` เป็น nullable |
| `ChunkedUploadController` | คอมเมนต์อธิบาย ไม่แตะ logic |
| `ChunkedUploadTest` | **+2 test** |
| `RoleGateCharacterizationTest` | `500` → `200` + บันทึกว่าใครแก้และเพราะอะไร |

### NULL ในตารางนี้แปลว่าอะไร

**"ไฟล์ที่ platform operator พักไว้ ยังไม่ผูกกับบริษัทไหน"**

chunked upload คือกองไบต์ชั่วคราวที่ยังไม่มีความหมายทางธุรกิจ — การผูกกับบริษัท
เกิดตอนเอา token ไปสร้างของจริง (`ResolveChunkedUpload` → Form Request + Policy
ของ endpoint นั้น) ซึ่งตรวจบริษัทอย่างถูกต้องอยู่แล้ว

### BR-6 ไม่ได้อ่อนลง — และต้องอธิบายเพราะมันดูเหมือนจะอ่อน

`TenantScope` เติม `where company_id = :own` ให้ Company Admin ซึ่ง **ไม่ match แถวที่เป็น NULL**
→ tenant มองไม่เห็น ต่อไฟล์ไม่ได้ เอา token ไปใช้ไม่ได้ · คนเดียวที่เข้าถึงได้คือ
Super Admin ซึ่งยกเว้นจาก scope อยู่แล้ว และต้องถือ token สุ่ม 64 ตัวอักษรที่
เซิร์ฟเวอร์เป็นคนสร้างเท่านั้น (ไม่เคยรับจาก client)

**มี test คุมข้อนี้โดยตรง:** `test_a_company_admin_cannot_touch_a_super_admins_unbound_upload`

### ทางเลือกที่ไม่เลือก

ให้ Super Admin ระบุ `company_id` ตอน `/uploads/init` — ตรงกับแพตเทิร์นที่อื่น
แต่ transport ฝั่ง frontend อยู่ใน `api/client.ts` และมี call site 6 จุด ซึ่งหลายจุด
**ไม่มีบริษัทอยู่ในมือโดยชอบธรรม** (การอัปโหลดบทเรียนได้บริษัทมาจาก module ใน URL)
— แก้ 6 จุดเพื่ออ้อม null ตัวเดียว ไม่คุ้ม · บันทึกไว้ใน migration แล้ว

## 4. ตรวจแล้ว

- reproduce ได้จริงบน local ด้วย stack trace เต็ม แล้วหายหลังแก้
- `php artisan test` → **1662 passed (6222 assertions)** — เดิม 1660, ใหม่ +2
- pint สะอาดทุกไฟล์ที่แตะ

## 5. หลัง deploy — 2 อย่างที่ต้องเช็คสำหรับคลิป 198 MB

**1. เพดานขนาดวิดีโอของบริษัท** — default **200 MB** (198 ผ่านฉิวเฉียด)
ปรับที่ **ตั้งค่าระบบ → ตั้งค่าวิดีโอ** หรือ `MEDIA_VIDEO_MAX_UPLOAD_MB` ใน `.env`

**2. `which ffmpeg` บนเซิร์ฟเวอร์** — ถ้าไม่มี:
- คลิปจะ**ไม่ถูกบีบอัด** เก็บ 198 MB เต็ม ๆ
- **ไม่มี thumbnail** (`thumbnail_path` เป็น null ตลอด) ซึ่งหน้าตาเหมือน "รูปเสีย"

`config/media.php` บันทึกไว้เองว่าเคยตรวจแล้ว `which ffmpeg` บน production ไม่คืนอะไร
ถ้ายังเป็นแบบนั้น ให้ตั้ง `FFMPEG_PATH` / `FFPROBE_PATH` ใน `.env` ของเซิร์ฟเวอร์
