# TASK-139 — ทดสอบ Omise บนเครื่อง local

ก่อนขึ้น production. เขียน 2026-08-23 โดย ag-lead.

---

## 0. อ่านตรงนี้ก่อน — ข้อเดียวที่พลาดกันบ่อยที่สุด

**ออเดอร์จะถูกประทับ gateway ตอนที่ "ถูกสร้าง" เท่านั้น**

`OrderService::createForReferral()` เขียน `orders.payment_provider` และ
`orders.gateway_mode` ลงไปตอนสร้าง แล้วไม่เคยอ่านจากบริษัทอีกเลย — เพราะลิงก์
`/pay/...` ที่ส่งไปให้ลูกค้าแล้วต้องไม่เปลี่ยนวิธีจ่ายเงินกลางคัน

แปลว่า **ออเดอร์ที่สร้างก่อนเปิดใช้งาน Omise จะเป็น `manual` ตลอดไป และจะไม่มีปุ่ม
บัตรขึ้นมาเด็ดขาด** ต้องตั้งค่า Omise → เปิดใช้งาน → **แล้วค่อยสร้างออเดอร์ใหม่**

ถ้าเผลอสร้างไปก่อน แก้ด้วย SQL ได้ (local เท่านั้น):

```sql
UPDATE orders SET payment_provider='omise', gateway_mode='test' WHERE id = <id>;
```

---

## 1. เตรียมเครื่อง

```bash
cd /Applications/MAMP/htdocs/agent

# 1a. migration ใหม่ 2 ตัว (ตาราง gateway settings + 3 คอลัมน์บน orders)
php artisan migrate --path=database/migrations/2026_08_22_120000_create_company_payment_gateway_settings_table.php
php artisan migrate --path=database/migrations/2026_08_22_120100_add_gateway_columns_to_orders_table.php
# หรือรัน php artisan migrate เฉย ๆ ถ้าไม่มี migration ค้างอื่น

# 1b. ล้าง config cache — มี config/payments.php ตัวใหม่
php artisan config:clear

# 1c. เปิด 3 process (คนละ terminal)
php artisan serve --port=8010          # backend
npm run dev --prefix frontend          # agent portal → agent.localhost:5178
npm run dev --prefix frontend-admin    # admin        → admin.localhost:5179
```

ต้องต่อเน็ตได้ด้วย — ทั้งการตรวจ key, การตัดบัตร และ `cdn.omise.co` ล้วนเป็นการ
เรียกออกไปข้างนอกทั้งหมด

---

## 2. เอา test key มาจากไหน

สมัคร Omise (dashboard.omise.co) ใช้บัญชีทดสอบได้ฟรี ไม่ต้องยืนยันธุรกิจ
ไปที่ **Keys** ในโหมด Test จะได้:

| ช่อง | หน้าตา |
|---|---|
| Public key | `pkey_test_...` |
| Secret key | `skey_test_...` |
| Webhook signature secret | อยู่หน้า **Webhooks** |

ระบบจะ**ปฏิเสธ**ถ้าใส่ key ผิดโหมด — test key ในโหมด live หรือ live key ในโหมด
test ก็ไม่ผ่านทั้งคู่ ตั้งใจให้เป็นแบบนั้น

---

## 3. ตั้งค่าใน Admin

1. เข้า `http://admin.localhost:5179` ล็อกอินเป็น Super Admin
2. **เลือกบริษัทที่แถบด้านบนก่อน** — หน้านี้ไม่ยอมแสดงอะไรเลยจนกว่าจะเลือก
3. เมนู **ตั้งค่า → ช่องทางรับชำระเงิน**
4. กรอก 3 ช่องของ Omise, ปล่อย **โหมดใช้งานจริง (Live) ปิดไว้**
5. กด **บันทึกและตรวจสอบการเชื่อมต่อ**

ระบบจะยิงไปที่ `GET https://api.omise.co/account` แล้วแสดง **อีเมลของบัญชีที่ตอบ
กลับมา** — อ่านให้ดีครับ ติ๊กถูกสีเขียวบอกไม่ได้ว่าคุณต่อผิดบัญชี แต่อีเมลบอกได้

6. กด **เปิดใช้งานช่องทางนี้**

ถ้า key ผิด → ไม่มีอะไรถูกบันทึกเลย ตั้งใจให้เป็นแบบนั้นเหมือนกัน — credentials ที่
ตรวจไม่ผ่านไม่ควรค้างอยู่ในตารางแล้วดูเหมือนตั้งค่าเรียบร้อย

**Webhook URL** ที่แสดงบนการ์ดนั้น บน local จะเป็น
`http://admin.localhost:8010/api/v1/webhooks/payments/omise/<company_id>` —
**อย่าเพิ่งเอาไปวางใน dashboard ของ Omise** เพราะ Omise ยิงเข้า localhost ไม่ได้
ดูข้อ 6

> หมายเหตุ: ตอนแรกช่องนี้ผมสร้าง URL จาก `window.location.origin` ซึ่งผิดทั้ง local
> (ได้ port 5179 ของ Vite) และ production (ตก `/backend` ตาม ADR-039) แก้แล้ว
> ให้อ่านจาก `VITE_API_BASE_URL` แทน เจอตอนตอบคำถามนี้พอดี

---

## 4. สร้างออเดอร์ที่ชำระได้จริง

`confirmPayment` มีกฎเรื่อง pipeline อยู่ ออเดอร์ลอย ๆ จึงไม่พอ ต้องมีครบ:

1. Agent ที่ **ผ่านใบรับรอง Basic** แล้ว (BR-1)
2. **Commission rule** ของสินค้านั้น + tier นั้น
3. Referral ที่เดิน pipeline มาถึง **ขั้นก่อน Complete Payment**
4. แล้วค่อยกดสร้างคำสั่งซื้อ

ทำผ่าน UI ปกติได้เลย และควรทำผ่าน UI ด้วย เพราะเป็นการทดสอบเส้นทางจริงไปในตัว

---

## 5. ทดสอบตัดบัตร (ส่วนนี้ทำบน local ได้ครบ)

เปิดลิงก์ `/pay/...` ของออเดอร์นั้นในเบราว์เซอร์

| # | ทำ | ต้องได้ |
|---|---|---|
| 1 | ดูหน้าจอ | มีปุ่ม "ชำระ ฿... ด้วยบัตร" + ป้าย **โหมดทดสอบ** สีเหลือง |
| 2 | กดปุ่ม | ฟอร์มของ Omise เด้งขึ้นมา (iframe ของ omise.co) |
| 3 | บัตร `4242 4242 4242 4242` วันหมดอายุอนาคต CVV อะไรก็ได้ | ออเดอร์เป็น **ชำระแล้ว** |
| 4 | ตรวจ DB | `commission_ledger` เพิ่ม **1 แถวเท่านั้น** |
| 5 | เปิด Admin → คำสั่งซื้อ/การชำระเงิน | แถวนั้นมีชิป "Omise", "โหมดทดสอบ", "ตัดบัตรสำเร็จแล้ว" |
| 6 | ออเดอร์ใหม่ + บัตร `4111 1111 1111 1140` | 422 พร้อมข้อความปฏิเสธของ Omise เอง ออเดอร์ไม่เปลี่ยน ไม่มีแถว ledger |
| 7 | ออเดอร์ใหม่ กดปุ่มรัว ๆ 2 ครั้ง | ครั้งที่สองถูกปฏิเสธ **ก่อน**เรียก Omise |

ข้อ 3 กับ 6 คือจุดที่พิสูจน์ว่าสมมติฐานเรื่องรูปแบบ response ของ Omise ถูกหรือผิด

ถ้ากดปุ่มแล้วฟอร์มไม่เด้ง เปิด Console ดู — น่าจะเป็น `cdn.omise.co` โหลดไม่ได้
(ad blocker / เน็ต) ซึ่งโค้ดจะขึ้นข้อความไทยบอก ไม่ใช่ปุ่มที่กดแล้วเงียบ

---

## 6. ทดสอบ webhook บน local

**Omise ยิงเข้า `localhost` ไม่ได้** มีสองทาง:

### ทาง ก — artisan command (แนะนำ ไม่ต้องพึ่งอะไรเลย)

ผมเขียนคำสั่งไว้ให้แล้ว มันอ่าน webhook secret จริงของบริษัทนั้น เซ็นด้วยวิธี
เดียวกับที่ `OmiseGateway::verifyWebhook()` ตรวจ แล้วยิงเข้าเครื่องตัวเอง
(รันได้เฉพาะ `APP_ENV=local`)

```bash
# ยิง webhook ปกติ — ใส่ id หรือ order_number ก็ได้
php artisan payment:simulate-webhook ORD-XXXXXXXX

# ส่งซ้ำด้วย charge id เดิม → ต้องยังมี ledger แถวเดียว
php artisan payment:simulate-webhook ORD-XXXXXXXX --charge=chrg_test_abc
php artisan payment:simulate-webhook ORD-XXXXXXXX --charge=chrg_test_abc

# ยอดไม่ตรง → ต้องถูกปฏิเสธ ไม่ประทับ charge id ด้วยซ้ำ
php artisan payment:simulate-webhook ORD-XXXXXXXX --amount=100

# บัตรถูกปฏิเสธ → ห้ามยกเลิกออเดอร์
php artisan payment:simulate-webhook ORD-XXXXXXXX --status=failed

# ไม่มีลายเซ็น → ต้องได้ 401
php artisan payment:simulate-webhook ORD-XXXXXXXX --unsigned

# เซ็นด้วย secret ผิด → ต้องได้ 401
php artisan payment:simulate-webhook ORD-XXXXXXXX --forge
```

คำสั่งจะพิมพ์ payload, สถานะ HTTP, แล้ว**อ่านออเดอร์กลับมาจาก DB** ให้ดู — เพราะ
HTTP 200 ในหลายเคสเป็นคำตอบที่ตั้งใจ (ห้ามให้ gateway retry) สิ่งที่เกิดขึ้นจริง
อยู่ในแถวข้อมูล ไม่ใช่ในสถานะ HTTP

**สองบรรทัดที่ห้ามข้ามคือ `--unsigned` กับ `--forge`** ถ้าอันไหนไม่ได้ 401 หยุดแล้ว
บอกผมทันที — แปลว่าใครก็ได้บนอินเทอร์เน็ตสั่งให้ออเดอร์เป็น "จ่ายแล้ว" และเขียนค่าคอม
ที่ลบไม่ได้

### ทาง ข — tunnel (ถ้าอยากเห็น webhook จริงจาก Omise)

```bash
brew install cloudflared
cloudflared tunnel --url http://localhost:8010
# ได้ URL https://xxxx.trycloudflare.com
```

เอา `https://xxxx.trycloudflare.com/api/v1/webhooks/payments/omise/<company_id>`
ไปวางใน Omise dashboard → Webhooks แล้วตัดบัตรอีกครั้ง

**ทาง ข เท่านั้นที่พิสูจน์ได้ว่าชื่อ header กับวิธีเซ็นของ Omise ตรงกับที่เราเดา**
ทาง ก พิสูจน์แค่ว่าโค้ดเราสอดคล้องกับตัวเอง (ตัวเซ็นกับตัวตรวจใช้สมมติฐานชุดเดียวกัน)
ถ้าอยากปิดความเสี่ยงข้อนี้จริง ๆ ก่อนขึ้น production ต้องทำทาง ข

---

## 7. สิ่งที่ทำบน local ไม่ได้

| เรื่อง | ทำไม | ทำที่ไหนแทน |
|---|---|---|
| webhook จริงจาก Omise | Omise ยิงเข้า localhost ไม่ได้ | ทาง ข ข้อ 6 หรือรอ staging/production |
| ตัดบัตรจริง | อยู่โหมด test | Phase 6 บน production ด้วยบัตรคุณเอง ฿20 |
| อีเมลแจ้งเตือน | `.env` ตั้ง `MAIL_MAILER=log` | ดูใน `storage/logs/laravel.log` แทน |

---

## 8. เช็คว่าไม่พังของเดิม

สำคัญพอ ๆ กับการทดสอบของใหม่ — บริษัทที่ยังใช้ `manual` ทุกเจ้าต้องทำงานเหมือนเดิมเป๊ะ

```bash
php artisan test          # ต้องได้ 1,866 ผ่าน
npm run test:unit --prefix frontend
npm run test:unit --prefix frontend-admin
```

แล้วเปิดลิงก์ `/pay/...` ของออเดอร์ **manual** เก่าสักอัน — ต้องเห็น QR พร้อมเพย์,
เลขบัญชี, ปุ่มอัปโหลดสลิป ครบเหมือนเดิม และ **ไม่มี**ปุ่มบัตร

> หมายเหตุ: `npm run test:unit` ผมรันเองไม่ได้ครับ — `node_modules` บนเครื่องคุณเป็น
> darwin-arm64 แต่ VM ที่ผมเข้าถึงผ่าน bridge เป็น linux-arm64 (`Cannot find module
> '@rolldown/binding-linux-arm64-gnu'`) ผมยก `omiseCard.spec.ts` ไปรันแยกใน
> container ได้เพราะไฟล์นั้นไม่ import อะไรจากโปรเจกต์เลย แต่ spec ตัวอื่น
> โดยเฉพาะ `OrderPaymentsView.spec.ts` ที่ผมไปแก้ view ของมัน ยังไม่ได้รัน
