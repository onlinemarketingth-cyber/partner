# ADR-039 — เอา API มาอยู่ origin เดียวกับหน้าเว็บ (ตัดปัญหา session ใช้ร่วมกัน)

**สถานะ:** ✅ ขั้นที่ 1–2 เสร็จแล้ว (2026-08-21) · กำลังจะเริ่มขั้นที่ 3
**mount point ที่ใช้จริง:** `/backend` (ไม่ใช่ `/api` — ดู §6.2 ว่าทำไม)
**ที่มา:** Security audit 2026-08-21 ข้อ V6 · แทนที่แผน D5 เดิมซึ่งพิสูจน์แล้วว่าใช้ไม่ได้

---

## 1. ปัญหา

`partner.syncvision.io` และ `admin.partner.syncvision.io` เป็นแค่ไฟล์ static ทั้งคู่
**session อยู่ที่ `api.partner.syncvision.io` ที่เดียว และทั้งสองแอปคุยกับโฮสต์นั้นเหมือนกัน**

cookie ผูกกับ**โฮสต์ที่คำขอวิ่งไปหา** ไม่ใช่โฮสต์ที่หน้าเว็บเปิดอยู่ ทั้งสองแอปจึงใช้ cookie ใบเดียว
ชื่อเดียว (`config/session.php` ตั้งชื่อจาก `APP_NAME` ตัวเดียว) → **session เดียว**

`AuthController::login()` เรียก `session()->regenerate()` ดังนั้นการล็อกอินฝั่งหนึ่งจะเขียนทับ cookie
ของอีกฝั่งเงียบ ๆ — เปิดสองแท็บ ล็อกอินคนละบัญชี แล้วรีเฟรชแท็บแรก จะเห็นว่ามันกลายเป็นบัญชีที่สอง

**ผลด้านความปลอดภัย ไม่ใช่แค่ UX:** ไม่มีเส้นแบ่งระดับ cookie ระหว่างแอปที่คนแปลกหน้าสมัครเองได้
(พื้นที่โจมตีกว้างที่สุด) กับคอนโซลที่อนุมัติจ่ายเงิน

---

## 2. ทำไมแผนเดิม (เพิ่ม `api-admin.` subdomain) ใช้ไม่ได้

**ไม่ใช่เพราะ `SESSION_DOMAIN` — แต่เพราะกฎการตั้ง cookie ของเบราว์เซอร์**

`api-admin.partner.syncvision.io` ตั้ง cookie ได้แค่สองแบบ:

| แบบ | ผล |
|---|---|
| host-only ของตัวเอง | JS ที่ `admin.partner.syncvision.io` **อ่าน `XSRF-TOKEN` ไม่เห็น** → ทุก POST/PUT/DELETE พังด้วย CSRF |
| `Domain=.partner.syncvision.io` | กลับไปใช้ร่วมกับ agent เหมือนเดิม |

**ตั้ง `Domain=admin.partner.syncvision.io` ไม่ได้** — โฮสต์ตั้ง cookie ให้ตัวเองหรือโดเมนแม่ได้เท่านั้น
ตั้งให้โฮสต์พี่น้องไม่ได้ ถอด `SESSION_DOMAIN` ก็ยังตัน

> **บันทึกความผิดพลาด:** เอกสารก่อนหน้าเขียนว่า "`SESSION_DOMAIN` ต้องไม่ตั้งค่า" ซึ่ง**ผิด**
> ในสถาปัตยกรรมปัจจุบันมันจำเป็น เพราะหน้าเว็บกับ API อยู่คนละโฮสต์ ถอดออกตอนนี้ = production พังทันที
> ที่ local ไม่เจอเพราะ `agent.localhost:5178` กับ `agent.localhost:8010` เป็น**โฮสต์เดียวกัน** cookie ไม่สนใจพอร์ต

---

## 3. ทางที่เลือก — A

ให้แต่ละแอปเรียก API **บนโฮสต์ของตัวเอง**

| | หน้าเว็บ | API (ใหม่) |
|---|---|---|
| agent | `partner.syncvision.io` | `partner.syncvision.io/api/*` + `/sanctum/*` |
| admin | `admin.partner.syncvision.io` | `admin.partner.syncvision.io/api/*` + `/sanctum/*` |

แล้ว**ถอด `SESSION_DOMAIN` ออกได้จริง** → cookie เป็น host-only → สอง origin สอง cookie คนละใบ
JS อ่าน `XSRF-TOKEN` ได้เพราะ same origin

**นี่คือรูปแบบเดียวกับที่ local ใช้อยู่แล้วและพิสูจน์แล้วว่าได้ผล** — local ไม่ได้แยก API ไปคนละ subdomain
แต่ให้แต่ละแอปมีโฮสต์ของตัวเองแล้ววาง API ไว้บนโฮสต์นั้น ต่างกันแค่พอร์ต ADR นี้คือการแปล
รูปแบบเดียวกันมาสู่ production โดยแทน "คนละพอร์ต" ด้วย "คนละ path" เพราะ production มีแค่ 443

**และเป็นรูปแบบที่ Laravel Sanctum ออกแบบมาให้ใช้** — same-domain SPA การแยกโฮสต์ API ออกมาคือสิ่งที่
บังคับให้ต้องตั้ง `SESSION_DOMAIN` ตั้งแต่แรก เอา API กลับมา same-origin แล้วปัญหาทั้งชุดหายพร้อมกัน

### 3.1 เหนือกว่าทางเลือกอื่นตรงไหน

**B (แยกชื่อ cookie ต่อแอป)** แก้อาการ ไม่แก้โรค — cookie ยังอยู่บน `.partner.syncvision.io` ทั้งคู่
session cookie เป็น HttpOnly อ่านไม่ได้ก็จริง **แต่ XSRF token ไม่ใช่ HttpOnly** XSS บน origin ของ agent
อ่านของแอดมินได้แล้วยิงคำสั่งเปลี่ยนข้อมูล CORS กันได้แค่ไม่ให้อ่านผลลัพธ์ — คำสั่งยังทำงาน
สำหรับระบบที่อนุมัติจ่ายเงิน "ยิงได้แต่อ่านผลไม่ได้" ยังเป็นความเสียหายเต็ม

**A ตัดที่ราก** — เบราว์เซอร์จะไม่ส่ง cookie ของแอดมินไปให้ origin ของ agent เลย ไม่ใช่กฎที่เราเขียน
แต่เป็นกฎที่เบราว์เซอร์บังคับ

---

## 4. 🔑 หลักการที่ทำให้ความเสี่ยงต่ำ — **เติม ไม่ใช่ย้าย**

**`api.partner.syncvision.io` ต้องอยู่ต่อ ห้ามปิด**

ลิงก์สาธารณะที่**ลูกค้าถืออยู่แล้ว** ชี้ไปที่โฮสต์นั้น — ลิงก์แชร์สินค้า หน้าจ่ายเงิน สื่อการขาย ลิงก์พันธมิตร
(`TrackedLink::publicUrl()` สร้างจาก `config('app.url')`) ปิดโฮสต์ = ลิงก์ในมือลูกค้าตายหมด

ดังนั้น `APP_URL` **ไม่ต้องแก้** งานนี้คือ**เพิ่มทางเข้าใหม่ใต้โฮสต์ของแต่ละแอป** แล้วให้ SPA เปลี่ยนไปใช้ทางใหม่
ทางเดิมยังเปิดไว้สำหรับลิงก์สาธารณะที่ไม่มี session

---

## 5. สิ่งที่ต้องแก้ — โค้ดน้อยมาก งานเกือบทั้งหมดอยู่ที่ hosting

| ไฟล์ | แก้อะไร | ขั้นที่ |
|---|---|---|
| `frontend/.env.production` | `VITE_API_BASE_URL=https://partner.syncvision.io` | 3 |
| `frontend-admin/.env.production` | `VITE_API_BASE_URL=https://admin.partner.syncvision.io` | 4 |
| `.env` (server) | ลบบรรทัด `SESSION_DOMAIN` | 5 |

**ไม่ต้องแก้:** `SANCTUM_STATEFUL_DOMAINS` (มีทั้งสองโฮสต์อยู่แล้ว) · `config/cors.php` (same origin ไม่ใช้ CORS
แต่คงไว้สำหรับลิงก์สาธารณะข้าม origin) · `APP_URL` (ดู §4) · โค้ด client (สร้าง URL เป็น
`${API_BASE_URL}/api/v1${path}` อยู่แล้ว เปลี่ยนแค่ base ก็พอ)

### 5.1 mount **จุดเดียว** ครอบทั้งสอง prefix — ฉบับแก้ไข 2026-08-21

ร่างแรกของ ADR นี้เขียนว่าต้อง symlink สอง prefix (`/api` + `/sanctum`) **ซึ่งผิด**

จาก `client.ts` มีสองชุดจริง:

```js
fetch(`${API_BASE_URL}/sanctum/csrf-cookie`)   // ชุดที่ 2
fetch(`${API_BASE_URL}/api/v1${path}`)          // ชุดที่ 1
```

แต่ Laravel **ตัด base path ให้เองจาก `SCRIPT_NAME`** ดังนั้น mount เดียวก็ครอบทั้งคู่:

```
mount /backend  →  /backend/api/v1/products     → Laravel เห็น /api/v1/products   ✅
                   /backend/sanctum/csrf-cookie → Laravel เห็น /sanctum/csrf-cookie ✅
```

`client.ts` ไม่ต้องแก้สักบรรทัด เปลี่ยนแค่ `VITE_API_BASE_URL` ให้ชี้มาที่ mount

**แต่คำเตือนเดิมยังจริง:** ถ้า mount ผิดจนชุดที่ 2 ไม่ถึง Laravel ระบบจะ **อ่านได้หมดแต่กดบันทึกอะไรไม่ได้เลย**
เพราะ CSRF handshake อยู่ที่ `/sanctum/csrf-cookie` — เป็นอาการพังอันดับหนึ่งของงานนี้ และเจอทันทีในนาทีแรก

---

## 6. แผนลงมือ 5 ขั้น — ทุกขั้นถอยกลับได้

| ขั้น | ทำอะไร | พังแล้วเกิดอะไร | ถอยยังไง |
|---|---|---|---|
| **1** | ทำ `/api` + `/sanctum` ให้ตอบใต้ทั้งสองโฮสต์ | **ไม่มีอะไรพัง** ยังไม่มีใครเรียกใช้ | ลบสิ่งที่เพิ่ง add |
| **2** | `curl` ยืนยันว่าตอบจริงทั้งสองโฮสต์ | — | — |
| **3** | สลับ **agent อย่างเดียว** → deploy → QA | agent พัง **admin ยังปกติ** | สลับ `.env.production` กลับ + deploy |
| **4** | สลับ **admin** → deploy → QA | admin พัง **agent ยังปกติ** | เหมือนขั้น 3 |
| **5** | ลบ `SESSION_DOMAIN` + `config:cache` | **ทุกคนหลุดล็อกอินพร้อมกัน** (คาดไว้แล้ว ไม่ใช่บั๊ก) | ใส่กลับ + `config:cache` |

> **ห้ามทำขั้น 5 ก่อนที่ 3 และ 4 จะเสร็จทั้งคู่** ถ้าแอปไหนยังเรียก `api.partner...` อยู่แล้วถอด
> `SESSION_DOMAIN` แอปนั้นจะ CSRF พังทันที
>
> ขั้น 5 ควรทำนอกเวลาทำการและแจ้งผู้ใช้ล่วงหน้า

### 6.2 🔴 ทำไม mount ต้องไม่ชื่อ `api` — บทเรียนจากการตรวจจริง

ตอนตรวจพบว่า `public_html/api` **เป็น symlink ไป `backend/public` อยู่แล้ว** ตั้งแต่ 2026-08-18
แต่ `https://partner.syncvision.io/api/v1/products` ตอบ **404**

เพราะ `SCRIPT_NAME` กลายเป็น `/api/index.php` → Laravel คำนวณ base path เป็น `/api` แล้ว**ตัดทิ้ง**
→ เห็น path เป็น `/v1/products` ซึ่งไม่มี route ไหนตรง

**ชื่อโฟลเดอร์ไปชนกับ prefix ของ route พอดี** — พิสูจน์ได้ว่าเป็นแบบนั้นจริงเพราะ
`/api/up` ตอบ 200 (health check ที่ `bootstrap/app.php` ลงทะเบียนไว้ที่ `/up`)
และ `/api/api/v1/products` ตอบ 401

จึงตั้ง mount ใหม่ชื่อ **`/backend`** ซึ่งไม่ชนกับ prefix ไหนเลย (`/api/*`, `/sanctum/*`, `/up`, `/storage/*`)
symlink `/api` เดิม**ไม่ถูกแตะ** ปล่อยไว้ตามเดิม

### 6.3 ⚠️ กับดักที่เกือบทำให้แอดมินล่มทุก deploy — แก้แล้วในการเปลี่ยนแปลงเดียวกัน

`scripts/deploy.sh` rsync ลง `public_html/admin/` ด้วย `--delete` และ **ไม่มี `--exclude` เลย**
symlink `admin/backend` จึงจะถูกลบทิ้งทุกครั้งที่ deploy → แอดมินตายทั้งแอป

คอมเมนต์ในไฟล์นั้นบันทึกไว้เองว่าเรื่องนี้เคยเกิดมาแล้วกับ `api/`:
*"every deploy quietly broke the API until the next manual fix"*

เพิ่ม `--exclude=/backend` ทั้งสองบรรทัดแล้ว **ในคอมมิตเดียวกับที่สร้าง symlink** เพื่อไม่ให้มีช่วงเวลาไหน
ที่ deploy จะกวาดมันทิ้ง

### 6.4 ✅ ผลจริงของขั้นที่ 1–2 (2026-08-21)

```
agent /backend/api/v1/products     → 401
agent /backend/sanctum/csrf-cookie → 204
admin /backend/api/v1/products     → 401
admin /backend/sanctum/csrf-cookie → 204
```

symlink ใช้งานได้บนเซิร์ฟเวอร์นี้ (คำถามที่ค้างมาตั้งแต่ต้น — ตอบแล้ว)

### 6.5 ℹ️ ขั้นที่ 3–4 จะยัง **ไม่** แก้ปัญหา session ชน และนั่นถูกต้องแล้ว

`SESSION_DOMAIN=.partner.syncvision.io` ยังอยู่จนถึงขั้นที่ 5 ดังนั้น cookie เดิมที่ออกโดย
`api.partner.syncvision.io` **ยังถูกส่งไปที่ `partner.syncvision.io/backend` ด้วย**

ผลข้างเคียงที่ดี: **ขั้นที่ 3–4 ไม่มีใครหลุดล็อกอิน** session เดิมไหลต่อได้เนียน ๆ
ผลที่ต้องรู้: **การทดสอบสองแท็บจะยังเตะกันอยู่จนกว่าจะจบขั้นที่ 5** — อย่าเข้าใจผิดว่าล้มเหลว

### 6.1 ขั้นที่ 1 — ต้องรู้ก่อนว่ามีอะไรอยู่แล้วบ้าง (บันทึกไว้เพื่ออ้างอิง — ทำเสร็จแล้ว)

`scripts/deploy.sh` มี `--exclude=/api --exclude=/api` อยู่ในคำสั่ง rsync ของ agent portal
แปลว่า **มีโฟลเดอร์ `public_html/api` อยู่จริง** และคนเขียน deploy รู้ว่าต้องกันไม่ให้ `--delete` ลบทิ้ง

```bash
ssh -p 65002 -i ~/.ssh/hostinger_deploy u995267164@145.79.25.96
P=/home/u995267164/domains/partner.syncvision.io/public_html

ls -la  "$P" | grep -E 'api|admin'
ls -la  "$P/api" 2>/dev/null | head -5
curl -s -o /dev/null -w "agent  /api/v1/products      : %{http_code}\n" https://partner.syncvision.io/api/v1/products
curl -s -o /dev/null -w "agent  /sanctum/csrf-cookie  : %{http_code}\n" https://partner.syncvision.io/sanctum/csrf-cookie
curl -s -o /dev/null -w "admin  /api/v1/products      : %{http_code}\n" https://admin.partner.syncvision.io/api/v1/products
```

**อ่านผลยังไง**

| ผล | แปลว่า | ขั้นที่ 1 เหลืออะไร |
|---|---|---|
| agent ได้ `401` + `204` | ฝั่ง agent **ทำไปแล้ว** | เหลือแค่ฝั่ง admin |
| agent ได้ `404`/`200` (SPA ตอบ) | ยังไม่มี | ต้องทำทั้งสองฝั่ง |
| `/api` เป็น symlink ชี้ไป `backend/public` | symlink ใช้ได้บนเครื่องนี้ | ทำฝั่ง admin ด้วยวิธีเดียวกัน |

**วิธีทำ (ถ้ายังไม่มี)** — symlink ทั้งสอง prefix ไปที่ front controller เดียวกัน

```bash
B=/home/u995267164/syncvision-partner/backend/public
A=/home/u995267164/domains/partner.syncvision.io/public_html          # agent
D=/home/u995267164/domains/partner.syncvision.io/public_html/admin    # admin

ln -s "$B" "$A/api"      ;  ln -s "$B" "$A/sanctum"
ln -s "$B" "$D/api"      ;  ln -s "$B" "$D/sanctum"
```

Laravel เลือก route จาก `REQUEST_URI` ซึ่งยังเป็น `/api/v1/products` เต็ม ๆ จึง match ได้ถูกต้อง

**ถ้าเซิร์ฟเวอร์ไม่ตาม symlink** (`Options -FollowSymLinks`) → ทางสำรองคือ `RewriteRule ... [P]` ซึ่งต้องมี
`mod_proxy` ซึ่ง shared hosting มักปิด **ถ้าถึงจุดนี้ให้หยุดแล้วปรึกษาก่อน อย่าเดา**

---

## 7. QA — จุดที่พังจริงมีไม่กี่จุด

**ถ้า A ทำผิด มันพังที่ CSRF เป็นหลัก** อาการคือ **"อ่านได้หมด แต่กดบันทึกอะไรไม่ได้เลย"**
ชัดมากและเจอทันที ไม่ใช่บั๊กที่ซ่อนตัว

ทำหลังขั้น 3 (agent) และซ้ำอีกรอบหลังขั้น 4 (admin):

- [ ] ล็อกอิน / ออกจากระบบ / รีเฟรชแล้วยังอยู่
- [ ] **กดบันทึกอะไรสักอย่าง** (สร้างลูกค้า / แก้สินค้า) ← ด่านที่พังถ้าผิด
- [ ] อัปโหลดไฟล์ (multipart)
- [ ] เปิดวิดีโอ / ดาวน์โหลดสลิป (Range request)
- [ ] **ลิงก์สาธารณะทุกกลุ่มยังเปิดได้** `/p/` `/pay/` `/c/` `/in/` `/l/` `/m/` ← ห้ามข้าม อยู่ในมือลูกค้าแล้ว
- [ ] console ไม่มี CORS error (same origin แล้วไม่ควรมี)

**ข้อสอบของงานนี้ ทำหลังขั้น 5:**

- [ ] เปิดสองแท็บ ล็อกอินคนละบัญชี รีเฟรชแท็บแรก → **ต้องยังเป็นบัญชีเดิม**

---

## 8. เติมชั้นสุดท้ายหลังจบขั้น 5 (ทางเลือก แนะนำ)

A ยังเหลือช่อง **cookie tossing** — subdomain ที่ถูกยึดเขียน cookie ชื่อเดียวกันโดยตั้ง
`Domain=.partner.syncvision.io` ได้ แล้วเบราว์เซอร์จะส่งไปให้แอดมินด้วยโดยแยกไม่ออกว่าอันไหนของจริง
ใช้ทำ session fixation ได้

ปิดด้วย **`__Host-` prefix**:

```
SESSION_COOKIE=__Host-syncvision_session
```

เบราว์เซอร์**บังคับ**ว่าชื่อขึ้นต้น `__Host-` ต้อง `Secure` + `Path=/` + **ห้ามมี `Domain`**
subdomain ไหนก็ตั้ง cookie ชื่อนี้ไม่ได้อีก — บังคับที่ระดับเบราว์เซอร์ ไม่ใช่ระดับโค้ดเรา

ใช้ได้เฉพาะเมื่อ `SESSION_DOMAIN` ไม่ได้ตั้ง = **หลังขั้น 5 เท่านั้น**
ต้องทำกับ XSRF cookie ด้วย ซึ่งต้อง override `ValidateCsrfToken` และแก้ชื่อที่ client อ่าน — งานแยกต่างหาก

---

## 9. สิ่งที่ ADR นี้ยังไม่ตอบ

- **`public_html/api` ตอนนี้คืออะไร** — ยังไม่ได้ตรวจ (§6.1) กำหนดว่าขั้นที่ 1 คือ "ยืนยัน" หรือ "สร้าง"
- **symlink ใช้ได้บนเครื่องนี้ไหม** — ต้องลองจริง ไม่มีทางรู้ล่วงหน้า
- **`partner.syncvision.io/admin/` ตอบ 200** (พบ 2026-08-21) — แอดมินคอนโซลเสิร์ฟจาก origin ของ agent ด้วย
  มีกฎกันไว้ใน `frontend/public/.htaccess` แล้ว รอ deploy · **ต้องปิดข้อนี้ก่อนหรือพร้อมกับ ADR นี้**
  ไม่งั้น origin ที่เราเพิ่งแยกจะยังทับกันอยู่ดี
