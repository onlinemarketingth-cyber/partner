# TASK-218 — Super Admin ไม่เข้าพอร์ทัลตัวแทน + บันทึกเรื่อง session ชนกัน

**Status:** implemented + verified in browser (2026-08-20) · **Owner:** ag-lead
**คำขอ:** human — "Lock ให้ Super Admin ให้หน้า Frontend ไม่ได้ ง่ายกว่า"

---

## 1. ต้นเหตุจริง — session เดียวกันทั้งสองแอป

เอกสารส่วนนี้สำคัญกว่าตัวแก้ อย่าลบทิ้ง

**ตรวจจริงบน production 2026-08-20:**

| ตรวจ | ผล |
|---|---|
| `GET /api/v1/me` จากหน้า admin | `{id:1, name:"kreangyot Ohuyhanapa", role:"super_admin"}` |
| หน้า agent portal | "สวัสดี, kreangyot" — **คนเดียวกัน** |
| `XSRF-TOKEN` อ่านได้จาก `admin.partner.syncvision.io` | ✅ ทั้งที่ถูก set โดย `api.partner.syncvision.io` |

cookie อ่านข้าม subdomain ได้ → `Domain=.partner.syncvision.io` →
**ทุกแอปใช้ session ก้อนเดียวกัน**

### เคยแก้แล้ว แต่แก้แค่ local

`frontend/.env` (2026-08-02) เขียนไว้ว่า ตราบใดที่สองแอปเรียก API ผ่าน
hostname เดียวกัน มันจะแชร์ login identity เดียวกันในเบราว์เซอร์เดียว
local จึงแยกเป็น `agent.localhost:8010` กับ `admin.localhost:8010`

**production ทั้งสองแอปยังเรียก `api.partner.syncvision.io` เหมือนกัน — การแก้ไม่ได้ถูกยกไปด้วย**

ยืนยันว่า local แยกจริง: agent app = `kreangyot test1 (agent)`,
admin app = `kreangyot Ohuyhanapa (super_admin)` พร้อมกันได้

### ทางแก้ที่แท้จริง (ยังไม่ทำ — human เลือกทางง่ายกว่าก่อน)

ให้แต่ละแอปเรียก API ผ่าน host ของตัวเอง แล้ว unset `SESSION_DOMAIN`
(cookie กลายเป็น host-only) — เลียนแบบ local เป๊ะ ๆ

ผมทดสอบไว้แล้วว่าทำได้:

| URL | ผล |
|---|---|
| `partner.syncvision.io/api/api/v1/…` | ✅ **200 · JSON จริง** (symlink `public_html/api` อยู่ใต้ root domain แล้ว) |
| `admin.partner.syncvision.io/api/api/v1/…` | ❌ ได้ index.html ของ SPA — ต้องเพิ่ม symlink |

ถ้าจะทำจริงต้อง: เพิ่ม symlink ใต้ `public_html/admin`, ใส่ `--exclude=/api`
ใน rsync ของ frontend-admin (ไม่งั้น `--delete` ลบทิ้งทุก deploy),
unset `SESSION_DOMAIN`, แก้ `VITE_API_BASE_URL` ทั้งสองแอป, rebuild, redeploy
· **ทุกคนถูก logout 1 ครั้ง**

---

## 2. สิ่งที่ทำจริงในรอบนี้

Super Admin ที่เปิดพอร์ทัลตัวแทน → **แจ้งบรรทัดเดียว แล้วพาไปหน้า Admin ทันที**

```
        ◌  (spinner)
  บัญชี Super Admin — กำลังพาไปหน้า Admin
  ถ้าไม่ถูกพาไปอัตโนมัติ กดที่นี่
```

หน่วง **1 วินาที** แล้ว `window.location.replace(VITE_ADMIN_APP_URL)`

- ใช้ `replace` ไม่ใช่ `assign` — ไม่งั้นกดปุ่ม Back จะเด้งซ้ำเป็นกับดัก
- มีลิงก์สำรองใต้ข้อความ เผื่อ redirect ไม่ทำงาน (ไม่งั้นเหลือแต่ spinner หมุนไม่จบ)
- ทำไมไม่หน่วง 0: redirect ที่ไม่มีสาเหตุให้เห็น ทำให้คนคิดว่าตัวเองกดอะไรผิด

> **r1 เคยเป็นการ์ดอธิบายยาว** (บอกว่า role คืออะไร ทำไม session ชนกัน มีปุ่ม logout)
> human ตอบว่า *"ไม่ต้องสรุป แจ้งเตือนแบบสั้นๆ ครับ แล้ว Rediect ไปที่หน้า admin ได้เลย"*
> — ถูกต้อง คนที่หลงมาที่นี่ไม่ได้อยากอ่านสรุป เขาอยากไปที่ที่ตั้งใจจะไป
> คำอธิบายย้ายมาอยู่ในเอกสารนี้แทน

**พูดให้ชัด: นี่ไม่ใช่ security boundary** — route guard ฝั่ง client
ไม่ได้ป้องกันอะไรเลย ทุก endpoint ถูกกั้นด้วย Policy/Ability ฝั่งเซิร์ฟเวอร์อยู่แล้ว
(CLAUDE.md §5) สิ่งที่หายไปคือ**ความสับสน** ไม่ใช่ช่องโหว่

### ไฟล์

| ไฟล์ | เปลี่ยน |
|---|---|
| `frontend/src/views/SuperAdminNoticeView.vue` | **ใหม่** — แจ้งบรรทัดเดียว + auto-redirect |
| `frontend/src/router/index.ts` | route `/admin-account` + guard |

### 3 การตัดสินใจที่จงใจ

1. **กันเฉพาะ route ที่ไม่ public** — หน้า token สาธารณะ (`/p/:token`,
   `/pay/:token`, `/l/:token`) ยังเปิดได้ เพราะเหตุผลที่ Super Admin จะเปิด
   แอปนี้ที่เป็นไปได้มากที่สุดคือ**เช็คว่าลิงก์ของตัวเองใช้ได้ไหม** ถ้ากันไว้ด้วย
   จะเกิดอาการ "เปิดลิงก์ตัวเองแล้วดูเหมือนพัง" ซ้ำรอยเดิม (เคสเดียวกับ
   `/register?ref=` ที่เคยถูกรายงานเมื่อ 2026-08-05)
2. **เฉพาะ `super_admin` ไม่รวม `company_admin`** — ยังไม่มีใครรายงานอาการ
   เดียวกันกับ role นั้น และการล็อก role ออกจากทั้งแอปด้วยการเดา คือสิ่งที่
   BR-7 บอกว่าอย่าตัดสินใจแทน human
3. **agent / คนที่ยังไม่ล็อกอิน เปิดหน้านี้ไม่ได้** — จะถูกส่งกลับหน้าแรก /
   หน้า login ก่อนที่ timer redirect จะทำงาน · ไม่งั้นการพิมพ์ URL ตรง ๆ
   จะเหวี่ยงตัวแทนไปหน้า Admin ที่เขาเข้าไม่ได้

## 3. ตรวจแล้ว

- `vue-tsc --noEmit` + `eslint` สะอาด ✓
- agent พิมพ์ `/admin-account` ตรง ๆ → เด้งกลับ `/` ✓ (กดจริงในเบราว์เซอร์)
- **ยังไม่ได้ทดสอบ path ของ Super Admin จริง** — local สอง session แยกกันอยู่แล้ว
  (agent app = agent, admin app = super_admin) จึงสร้างสถานการณ์ "super_admin
  เปิดแอปตัวแทน" ไม่ได้โดยไม่ล็อกอินใหม่ · **ทดสอบได้จริงหลัง deploy**: เปิด
  `partner.syncvision.io` ขณะล็อกอินเป็น Super Admin ต้องเด้งไป
  `admin.partner.syncvision.io` ภายใน ~1 วินาที

## 4. ยังเหลืออยู่

ปัญหา session ชนกัน**ยังอยู่** — แค่ไม่แสดงอาการที่น่าสับสนที่สุดแล้ว
ที่ยังเป็นจริง:

- ล็อกอินเป็นตัวแทนในพอร์ทัลตัวแทน → **session แอดมินถูกแทนที่**
- ออกจากระบบที่แอปหนึ่ง → **ออกทั้งสองแอป**
- ทดสอบมุมมองตัวแทนต้องใช้**หน้าต่างไม่ระบุตัวตน**

ถ้าจะปิดจบจริง ต้องทำตามข้อ 1 ท้ายหัวข้อ
