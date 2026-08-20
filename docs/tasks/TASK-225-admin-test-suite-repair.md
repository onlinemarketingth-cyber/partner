# TASK-225 — ซ่อมชุดเทสต์ของแอดมิน (แดงมาตั้งแต่ 17 ส.ค.)

**Status:** implemented · **frontend-admin 106/106 · frontend 132/132**
**Owner:** ag-lead · **จุดเริ่ม:** human รัน `npm run test:unit` แล้วเจอ **56 failed | 50 passed**

---

## 1. ไม่ใช่ของใหม่ — แดงมา 3 วันแล้ว

ผมติดตั้ง node_modules ใหม่บน Linux ในคลาวด์แล้วรันชุดเทสต์จริง **ได้ตัวเลขเดียวกันเป๊ะ**
กับที่ human เจอ (56 failed | 50 passed) จึงยืนยันได้ว่า reproduce ได้และไม่ใช่เรื่องเครื่อง

หลักฐานว่าเป็นของเก่า:

```
frontend-admin/src/views/SalesTeamView.vue           แก้ล่าสุด 2026-08-20
frontend-admin/src/views/__tests__/SalesTeamView.spec.ts   แก้ล่าสุด 2026-08-17
```

view ขยับ แต่ spec ไม่ขยับตาม · **ไม่มีใครรันเทสต์ตั้งแต่วันที่ 17**

> เรื่องนี้สำคัญกว่าตัวเลข: **ชุดเทสต์ที่แดงอยู่แล้ว ปิดบัง regression ของตัวเอง**
> บั๊ก TASK-222 (500 ที่ถูกบันทึกไว้ว่า "คาดหวัง") รอดสายตามาได้ก็ด้วยเหตุผลเดียวกัน

## 2. สาเหตุเดียว ทำให้พัง 5 ไฟล์

```
Error: [🍍] "getActivePinia()" was called but there was no active Pinia
  ❯ setup src/views/SalesTeamView.vue:94
  ❯ setup src/views/ThemeSettingsView.vue:362
  ❯ setup src/views/AcademyManagementView.vue:395
  ❯ setup src/views/AgentCommissionSummaryView.vue:77
  ❯ setup src/views/ReferralPipelineManagementView.vue
```

**ADR-038 / TASK-209** เพิ่ม store กลาง "ตอนนี้ทำงานในบริษัทไหน" และทุก view ที่ scope ได้
เรียก `useActiveCompanyStore()` ใน `setup()` ของตัวเอง → **การ mount view เหล่านี้
ทำไม่ได้อีกต่อไปถ้าไม่มี Pinia** · spec เขียนไว้ก่อนหน้านั้นจึงพังพร้อมกันหมด

### แก้ด้วยไฟล์เดียว ไม่ใช่แก้ 5 ที่

`frontend-admin/vitest.setup.ts` (ใหม่) + ลงทะเบียนใน `vitest.config.ts`

```ts
beforeEach(() => {
  const pinia = createPinia()
  setActivePinia(pinia)          // สำหรับ store ที่เรียกนอก component
  config.global.plugins = [pinia] // สำหรับ store ที่เรียกใน setup() ของสิ่งที่ mount
})
```

**ต้องมีทั้งสองบรรทัด** — อันหนึ่งไม่ครอบคลุมอีกอัน

**Pinia ใหม่ทุก test ไม่ใช่ตัวเดียวใช้ร่วม** — store มี state ถ้าบริษัทที่เลือกใน test หนึ่ง
รั่วไป test ถัดไป จะกลายเป็น failure ที่ขึ้นกับลำดับการรัน ซึ่งหาสาเหตุกันทั้งบ่าย

**แก้ที่เดียวเพราะ "view ที่ mount ได้ต้องมี Pinia" เป็นคุณสมบัติของแอป ไม่ใช่ของ spec แต่ละไฟล์**
ถ้าไปเติม 4 บรรทัดเดียวกันใน 5 ไฟล์ view ที่ 6 ก็จะมาค้นพบเรื่องนี้ใหม่อีกรอบ

## 3. เหลืออีก 1 test ที่พังคนละเหตุผล — และมันบอกอะไรบางอย่าง

```
× leaves the three per-company setting cards outside the tabs (§3 D2)
```

test นี้ยืนยันว่า **ตั้งค่าวิดีโอ / การมองเห็นข้อมูลทีม / คอมมิชชั่นตัวแทนร่วม**
ยังอยู่ในหน้าธีม — ซึ่ง **TASK-202 (17 ส.ค.) ย้ายทั้งสามออกไปเป็นหน้าของตัวเองแล้ว**

คือ test ที่ยืนยันข้อกำหนดที่ human ตัดสินใจกลับทางไปแล้ว · **assertion นี้เป็นเท็จ
มาตั้งแต่วันที่ 17 และไม่มีใครเห็น** เพราะไฟล์นี้แดงอยู่ด้วยเหตุผลอื่นพอดี

**เปลี่ยนเป็นยืนยันด้านตรงข้าม ไม่ใช่ลบทิ้ง** — "ทั้งสามต้องไม่อยู่ที่นี่แล้ว" ยังคุ้มที่จะ pin ไว้
เพราะการย้ายกลับมาจะเป็นการล้ม TASK-202 แบบเงียบ ๆ และคอมเมนต์ระบุ route ใหม่ไว้ให้
คนอ่านตามไปเจอ

## 4. ผล

| | ก่อน | หลัง |
|---|---|---|
| `frontend-admin` | 5 ไฟล์แดง · **56 failed / 50 passed** | **9 ไฟล์เขียว · 106 passed** |
| `frontend` | — | **13 ไฟล์เขียว · 132 passed** (เขียวอยู่แล้ว รวม spec ใหม่ของ TASK-224) |

## 5. ทำไมคราวนี้ผมรันเองได้

sandbox ใช้ `node_modules` ของ macOS ไม่ได้ (rolldown native binding) — คราวก่อนจึงรันไม่ได้เลย
รอบนี้ผมคัดลอกซอร์ส (ไม่เอา node_modules) ขึ้นคลาวด์แล้ว `npm install` ใหม่บน Linux
**ต่อจากนี้ตรวจ frontend ทั้งสองแอปด้วยเทสต์จริงได้แล้ว ไม่ต้องเดา**
