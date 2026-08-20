# TASK-212 — ส่งลิงก์ทางอีเมล "ผ่านระบบ" แทน mailto:

**Status:** implemented (2026-08-19) · **Owner:** ag-lead · **Type:** feature

## 1. คำขอ

> "ระบบ อีเมล์ให้ส่งผ่านระบบ" — human, 2026-08-19, บนโมดัลแชร์ลิงก์ชำระเงิน
> (`ชำระเงิน ORD-00KDZTH7`)

**คำตอบจาก AskUserQuestion:**

| คำถาม | คำตอบ |
|---|---|
| ขอบเขต | **ทุกที่ที่มีปุ่มอีเมล** |
| ผู้รับ | **ดึงอีเมลลูกค้ามาให้ แก้ไขได้** |
| ถ้ายังไม่ตั้งค่า SMTP | **ใช้ email smtp ที่ตั้งค่าไว้** |

ข้อ 3 ตีความว่า "ให้ไปทางระบบเสมอ" จึงไม่ทำ fallback กลับไป `mailto:`
เมื่อระบบเมลปิด แต่ขึ้นข้อความไทยที่บอกตรง ๆ ว่าแอดมินยังไม่ได้เปิดใช้งาน
(ดู §5) — เงียบแล้วแกล้งว่าสำเร็จเป็นสิ่งที่แย่กว่า

## 2. ทำไม mailto: ถึงไม่พอ

ปุ่มเดิมทำ `window.location.href = 'mailto:?subject=...'` ซึ่งบนมือถือ —
พื้นผิวหลักของแอปนี้ — จะโยนงานให้แอปเมลที่ติดตั้งอยู่ หรือ **ไม่เกิดอะไรเลย
อย่างเงียบ ๆ** ถ้าไม่มี และถึงเกิด อีเมลก็ออกจาก**อีเมลส่วนตัวของตัวแทน**
โดยระบบไม่มีบันทึกว่าเคยส่ง

## 3. สถาปัตยกรรม — ทำไม browser ไม่ส่ง URL

`POST /api/v1/share-emails` รับ **`{type, id, email}` ไม่ใช่ `{url, email}`**

ถ้ารับ URL จากฝั่ง client ระบบนี้จะกลายเป็น **open relay ที่ยืนยันตัวตนแล้ว**
ใครที่มีบัญชีตัวแทนจะส่งลิงก์อะไรก็ได้ไปหาใครก็ได้ โดยออกจากโดเมนที่ผ่านการ
ยืนยันของบริษัท = โครงสร้างพื้นฐานฟิชชิงที่เราจัดหาให้เอง

เซิร์ฟเวอร์จึงรับแค่ `ShareLinkType` (3 ค่า) + `id` แล้ว:

1. หา model (ผ่าน `TenantScope` — ข้ามบริษัทได้ 404 ไม่ใช่ 403)
2. ถาม **Policy เดิม** ว่าดูได้ไหม (`OrderPolicy` / `ProductShareLinkPolicy` /
   `AgentInviteLinkPolicy`) — ไม่มีกฎใหม่ที่จะ drift ตามมา
3. **สร้าง URL เอง** ด้วยการประกอบชุดเดียวกับที่ Resource ใช้
   (`OrderResource::publicPayUrl()` ฯลฯ)

ผลคือตัวแทนส่งได้เฉพาะลิงก์ที่ตัวเองเปิดดูได้อยู่แล้ว และ URL ในเมลคือ URL
ที่โค้ดนี้สร้าง

## 4. ไฟล์

**Backend (ใหม่)**

| ไฟล์ | หน้าที่ |
|---|---|
| `app/Enums/ShareLinkType.php` | `order` / `product_share` / `agent_invite` |
| `app/Mail/ShareLinkMail.php` | เมลไทย heading + ลิงก์ + ชื่อผู้ส่ง |
| `app/Services/Share/ShareLinkEmailService.php` | resolve → authorize → build URL → send |
| `app/Http/Requests/Share/SendShareLinkEmailRequest.php` | validate + บังคับ `email` เมื่อไม่มีผู้รับปริยาย |
| `app/Http/Controllers/Api/V1/ShareLinkEmailController.php` | แปลง 2 ความล้มเหลวเป็น 422 ภาษาไทย |
| `tests/Feature/Share/ShareLinkEmailTest.php` | 9 เคส |

**Backend (แก้)**

- `routes/api.php` — `POST /share-emails` + `throttle:10,1` (ระดับเดียวกับ
  `/register/resolve-invite-code`; เหตุผลเดียวกับที่คอมเมนต์ของ
  `resend-verification-email` เขียนไว้ว่า endpoint ส่งเมลที่ไม่จำกัดอัตรา
  คือ "a free mail cannon" — และอันนี้รับอีเมลผู้รับตามใจผู้เรียก)
- `app/Http/Resources/OrderResource.php` — `client_email` (`whenLoaded`)
  สำหรับ prefill

**Frontend (`frontend/`)**

- `design-system/components/ShareLinkModal.vue` — ปุ่มอีเมลเปิดช่องกรอกผู้รับ
  (prefill + แก้ได้) → `POST /share-emails` พร้อมสถานะกำลังส่ง/สำเร็จ/ผิดพลาด
- `composables/useReferralOrders.ts` — เพิ่ม `shareOrderId`, `shareDefaultEmail`
- `views/OrdersView.vue`, `views/ClientsView.vue`,
  `views/ProductBrowseView.vue`, `views/MyTeamView.vue` — ส่ง `email-*` props

## 5. การตัดสินใจที่ควรรู้

**Fail closed เมื่อระบบเมลปิด.** `MailSettingsService::applyRuntimeConfig()`
จะไม่สลับ `mail.default` ออกจาก mailer `log` ใน `.env` ถ้าไม่มีแถวตั้งค่า
หรือ `is_enabled = false` แปลว่าถ้าเราสั่งส่งตอนนั้น เมลจะถูก **เขียนลง log
แล้วรายงานว่าสำเร็จ** — ตัวแทนเดินจากไปโดยเชื่อว่าลูกค้าได้ลิงก์แล้ว จึงเช็ค
ก่อนเรียก `Mail::to()` และโยน `MailSettingsNotConfiguredException` ตัวเดียวกับ
ที่หน้าตั้งค่าใช้อยู่ (กฎเดียวกับ `PlatformMailSettingService::sendTest()`)

**ผู้รับปริยายมีเฉพาะออเดอร์.** ลิงก์แชร์สินค้าและลิงก์ชวนทีมเป็นลิงก์
broadcast ไม่มี "ผู้อ่านที่ตั้งใจ" คนเดียว ระบบจึงบังคับให้ตัวแทนกรอกอีเมลเอง
(`SendShareLinkEmailRequest::withValidator()`)

**ไม่ queue** และ **ไม่ใช้ Blade** — เหตุผลเดียวกับ `OrderPaymentConfirmedMail`
(queue:work ไม่การันตีว่ารันอยู่ / CLAUDE.md §3 ห้าม Blade ในรีโปนี้)

**`ShareLinkModal` เลิกเป็น component ที่ไม่คุยกับ API แล้ว** — docblock เดิม
เขียนไว้ชัดว่า "never talks to the API itself" ผมแก้พร้อมเหตุผล ทางเลือกคือ
emit event ให้ 4 หน้าไป POST เอง ซึ่งจะได้ช่องกรอก + flag กำลังส่ง +
error handling ซ้ำกัน 4 ชุด

**Host ที่ไม่ได้ต่อ prop ยังใช้ `mailto:` เดิม** — degrade แทนที่จะเรนเดอร์ปุ่ม
ที่กดแล้วทำงานไม่ได้ (บทเรียนจาก TASK-211)

## 6. ตรวจแล้ว / ยังไม่ตรวจ

- PHPUnit เต็มชุด: **1613 passed** (6072 assertions) — เดิม 1604 + 9 เคสใหม่
- `Mail::fake()` ทั้งไฟล์เทสต์ ไม่เปิดการเชื่อมต่อ SMTP จริง
- `vue-tsc --noEmit` + `eslint` สะอาดทั้ง `frontend`
- **ยังไม่ได้กดจริงในเบราว์เซอร์ และยังไม่เคยส่งเมลจริงผ่าน SMTP จริง**
  — ต้องมีคนตั้งค่า SMTP ที่หน้า Super Admin ก่อน

## 7. QA

1. Super Admin → ตั้งค่า SMTP → **ทดสอบส่งอีเมล** ต้องผ่านก่อน มิฉะนั้นข้อ
   ต่อไปจะได้ 422 อย่างถูกต้อง
2. ตัวแทน → คำสั่งซื้อ → แชร์ → **อีเมล** → ช่องผู้รับต้อง **เติมอีเมลลูกค้า
   มาให้แล้ว** → ส่งอีเมล → ขึ้น "ส่งอีเมลไปที่ ... แล้ว" และลูกค้าได้เมล
   ที่มีลิงก์ `/pay/{token}`
3. แก้เป็นอีเมลอื่นแล้วส่ง → ต้องไปที่อีเมลที่พิมพ์
4. หน้าสินค้า → แชร์ → อีเมล → ช่อง **ว่าง** (ไม่มีผู้รับปริยาย) กดส่งทั้งที่
   ว่าง → "กรุณากรอกอีเมลผู้รับ"
5. ทีมของฉัน → ลิงก์ชวนทีม → อีเมล → ส่งได้ ลิงก์เป็น `/register?ref=...`
6. **ทดสอบความปลอดภัย:** ล็อกอินเป็นตัวแทน A แล้วยิง
   `POST /api/v1/share-emails {type:'order', id:<ออเดอร์ของตัวแทน B>}`
   ด้วยมือ → ต้องได้ 403 (บริษัทเดียวกัน) หรือ 404 (คนละบริษัท) และ
   **ต้องไม่มีเมลออก**
7. กดส่งรัว ๆ เกิน 10 ครั้งใน 1 นาที → ต้องได้ 429

## 8. ที่ยังไม่ได้ทำ

- **ไม่มี audit log** ของการส่งลิงก์ (ต่างจาก `platform_mail_settings.test_sent`
  ที่มี) ถ้าต้องการรู้ว่าใครส่งลิงก์ไหนไปหาใครเมื่อไหร่ — บอกได้ เป็นงานเล็ก
- ยังไม่มีปุ่มอีเมลใน `frontend-admin` (แอดมินไม่มี ShareLinkModal)
