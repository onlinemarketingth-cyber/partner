# TASK-217 — ชุดสีกลาง (shared theme presets) + คำตอบเรื่อง SMTP

**Status:** implemented · backend tested (1641 passed) · **ยังไม่ได้รัน migration บนเครื่องคุณ**
**Owner:** ag-lead · **คำขอ:** human 2026-08-20 — "ช่วยแก้การบันทึกสีให้ใช้ได้กับทุกบริษัท และ เรื่องการตั้งค่า SMTP ด้วยครับให้ใช้ค่ากลางทุกบริษัท"

---

## 1. SMTP — ตรวจแล้ว **เป็นค่ากลางอยู่แล้ว ไม่ต้องแก้อะไร**

`platform_mail_settings` **ไม่มีคอลัมน์ `company_id` เลย** — มีแถวเดียวทั้งแพลตฟอร์ม
โดยตั้งใจตั้งแต่ TASK-190 §3.1 และเขียนเหตุผลไว้ในหัว migration ตรง ๆ

`MailSettingsService::applyRuntimeConfig()` ถูกเรียกจาก `AppServiceProvider::boot()`
**ทุก request** โดยไม่สนใจว่า user อยู่บริษัทไหน แล้วเขียนทับ `mail.mailers.smtp`,
`mail.from` และ `mail.default` — เมล์ทุกฉบับของทุกบริษัทจึงออกจากกล่องเดียวกัน

Fail closed: ถ้า `is_enabled = false` หรือยังไม่มีแถว จะไม่ทำอะไรเลย
(`.env` MAIL_MAILER=log เดิมชนะ) — ไม่มีทางที่ระบบที่ยังไม่ตั้งค่าจะแอบยิง SMTP จริง

แก้เฉพาะ **Super Admin** เท่านั้น (`Ability::SettingsMailUpdate`)

> **สรุป: ข้อนี้ไม่มีงานต้องทำ** ถ้าปุ่มอีเมลบน production ยังไม่ทำงาน
> สาเหตุคือยังไม่ได้ **กรอกค่า** ไม่ใช่ว่าค่ามันแยกรายบริษัท

---

## 2. ชุดสี — เพิ่ม "ชุดกลาง" ที่ใช้ได้ทุกบริษัท

### เดิม
`theme_presets.company_id` เป็น **NOT NULL** — ไม่มีแนวคิดชุดกลางเลย
"ชุดมาตรฐาน" 5 ชุด (ม่วงพรีเมียม ฯลฯ) ไม่ใช่ชุดกลาง แต่เป็นการ **ก็อปปี้แถวลงทุกบริษัท**
ตอนสร้างบริษัท ต้นฉบับอยู่ใน `config/theme_presets.php`

### ใหม่
`company_id` เป็น **NULLABLE** และ **NULL = ชุดกลาง** เป็นของแพลตฟอร์ม ไม่ใช่ของบริษัทไหน

| ใคร | ทำอะไรได้กับชุดกลาง |
|---|---|
| Super Admin | สร้าง (ติ๊ก "ใช้ร่วมทุกบริษัท") · ใช้ · เปลี่ยนชื่อ · ลบ |
| Company Admin | **เห็น · ใช้ได้** — แต่เปลี่ยนชื่อ/ลบไม่ได้ (422) |
| Agent | ไม่เห็นอะไรเลย เหมือนเดิม (403 ทุก route) |

**กด "ใช้ชุดนี้" กับชุดกลาง → สีลงที่บริษัทที่เลือกอยู่** นี่คือหัวใจของฟีเจอร์

### ทำไมใช้ NULL ไม่ใช่ flag `is_shared`
boolean คู่กับ `company_id` ที่ยัง NOT NULL จะทำให้ทุกแถวกลางมีชื่อบริษัทติดอยู่
ทั้งที่ไม่ได้เป็นของบริษัทนั้น และทุก query ต้องจำว่าให้เมิน column นั้น
NULL พูดความจริงว่า "แถวนี้ไม่มีเจ้าของ" และทำให้ query ที่เขียนผิด **หายไปเลย**
(`where company_id = X` ไม่คืนแถวกลาง) แทนที่จะเงียบ ๆ คืนของผิด

### BR-6 ไม่ได้อ่อนลง
preset เก็บ **เฉพาะพื้นผิวสี** (`COLOR_FIELDS` — hex, gradient config, keyword เงา)
ไม่มีชื่อ โลโก้ ลูกค้า ราคา หรืออะไรที่ผูกกับ tenant การแชร์ hex ข้ามบริษัท
ไม่ใช่การรั่วข้อมูล — มันคือแพลตฟอร์มแจกจานสี ซึ่งชุดมาตรฐาน 5 ชุดก็ทำอยู่แล้ว
(แค่ทำด้วยการก็อปปี้ N ครั้ง) · **preset ที่มีเจ้าของยังถูกกั้นเหมือนเดิมทุกประการ**

---

## 3. ไฟล์ที่แก้

### Backend
| ไฟล์ | เปลี่ยน |
|---|---|
| `database/migrations/2026_09_05_090000_make_theme_presets_company_id_nullable.php` | **ใหม่** — `company_id` เป็น nullable · `down()` ปฏิเสธถ้ามีชุดกลางค้างอยู่ |
| `app/Models/Scopes/SharedOrTenantScope.php` | **ใหม่** — `where (company_id = :own or company_id is null)` ใน closure ซ้อน (ไม่ซ้อน = ทั้ง chain กลายเป็น OR = รั่ว) |
| `app/Models/Scopes/TenantScope.php` | แยก `actor()` / `seesEverything()` ออกมาให้ subclass ใช้ re-entrancy guard ตัวเดียวกัน |
| `app/Models/ThemePreset.php` | ใช้ `SharedOrTenantScope` แทน `TenantScope` |
| `app/Services/Theme/ThemePresetService.php` | `snapshot(..., bool $shared)` · `apply()` ยอมให้ชุดกลางลงบริษัทผู้เรียก · `guardMayChangeShared()` |
| `app/Http/Controllers/Api/V1/ThemePresetController.php` | `index()` = ของบริษัท + ชุดกลาง (ชุดกลางขึ้นก่อน) · ส่ง actor เข้า service |
| `app/Http/Requests/Theme/StoreThemePresetRequest.php` | รับ `is_shared` · **strip ทิ้งถ้าไม่ใช่ Super Admin** |
| `app/Http/Requests/Theme/Concerns/ResolvesPresetCompany.php` | แยก `prepareCompanyForValidation()` ออกมาให้ override ได้ (ไม่งั้น trait ถูก shadow เงียบ ๆ) |
| `app/Policies/ThemePresetPolicy.php` | `view()` ปล่อยชุดกลางผ่าน · `update()` ปฏิเสธ Company Admin ที่จะแก้ชุดกลาง |
| `app/Http/Resources/ThemePresetResource.php` | เพิ่ม `is_shared` |
| `tests/Feature/Theme/ThemePresetTest.php` | **+10 test** |

### Frontend
`frontend-admin/src/views/ThemeSettingsView.vue` — checkbox "บันทึกเป็นชุดกลาง"
(Super Admin เท่านั้น), ป้าย **ชุดกลาง** สีแบรนด์, `canEditPreset()` คุมปุ่มดินสอ/ถังขยะ

---

## 4. ทดสอบแล้ว

- `php artisan test` → **1641 passed (6162 assertions)** — เดิม 1631, ใหม่ +10
- `pint` สะอาด · `vue-tsc` + `eslint` สะอาด
- test ที่สำคัญที่สุด: `test_a_company_admin_cannot_rename_or_delete_a_shared_preset`
  และ `test_the_list_adds_shared_presets_without_leaking_another_companys`
  (การเปิดให้เห็นชุดกลาง ต้องไม่ลากของบริษัทอื่นเข้ามาด้วย)
- กดในเบราว์เซอร์: checkbox ขึ้นจริง หน้าโหลดปกติ

**ยังไม่ได้ทดสอบ:** การกดบันทึกชุดกลางจริง — ต้องรัน migration ก่อน (ดูข้อ 5)

---

## 5. ต้องทำก่อนใช้งาน

```bash
cd backend && php artisan migrate
```

ตอน deploy จริง `npm run deploy` รัน `migrate --force` ให้เองอยู่แล้ว

## 6. ที่จงใจไม่ทำ

**ไม่** ยุบชุดมาตรฐาน 5 ชุดที่ก็อปอยู่ทุกบริษัทให้เหลือ 5 แถวกลาง —
เป็น data migration ข้ามทุก tenant เพื่อแก้ความซ้ำซ้อนที่ยังไม่มีใครบ่น
และไม่ควรทำวันเดียวกับที่ column เปลี่ยนรูป · ทำทีหลังได้บน schema ที่รองรับแล้ว
