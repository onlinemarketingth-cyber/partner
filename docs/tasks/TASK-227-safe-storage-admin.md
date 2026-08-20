# TASK-227 — `localStorage.getItem is not a function` (42 tests แดงบนเครื่อง human)

**Status:** implemented · frontend-admin 106 passed · frontend 132 passed · **Owner:** ag-lead
**คำขอ:** human — *"npm run test:unit ครั้งก่อนยัง Fail"* (42 failed / 42 passed)

---

## 1. อาการที่ขัดแย้งกันเอง

```
TypeError: localStorage.clear is not a function      ← spec beforeEach
TypeError: localStorage.getItem is not a function    ← AcademyManagementView.vue:2118
```

commit เดียวกัน, `package.json` เดียวกัน, `vitest.config.ts` เดียวกัน:

| | ผล |
|---|---|
| sandbox (Linux) | **106 / 106 ผ่าน** |
| เครื่อง human (macOS) | **42 fail** ใน 3 ไฟล์ |

ตรวจแล้วไม่ใช่เวอร์ชัน — `vitest 4.1.10`, `jsdom 29.1.1`, `vite 8.1.3` ตรงกันทั้งสองเครื่อง
ลอง Node 22 และ Node 24 ใน sandbox ก็ผ่านทั้งคู่ และเลขบรรทัดในไฟล์เทสต์ (240 / 220) ตรงกันเป๊ะ
แปลว่า **โค้ดเหมือนกันทุกตัวอักษร ต่างกันแค่ environment**

## 2. พิสูจน์สาเหตุด้วยการจำลอง ไม่ใช่เดา

ใส่ setup file ชั่วคราวที่แทน `localStorage` ด้วย `{}` เปล่า ๆ แล้วรันใน sandbox:

```
Tests  42 failed | 64 passed (106)
Test Files  3 failed | 6 passed (9)
TypeError: localStorage.clear is not a function
TypeError: localStorage.getItem is not a function
```

**เลข 42 และ 3 ไฟล์ตรงกับของ human ทุกตัว** → บนเครื่อง human `localStorage` มีอยู่จริง
แต่เป็น object ที่ไม่มี method ของ Storage เลย

ทำไมดูเหมือนสุ่ม? เพราะ **ทุกไฟล์ที่แตะ Storage ตาย ทุกไฟล์ที่ไม่แตะรอด** — ไม่ใช่เรื่องบังเอิญ

## 3. เรื่องนี้เคยเกิดแล้ว และโปรเจกต์เคยตอบไว้แล้ว

`frontend/src/utils/safeStorage.js` มี docblock เขียนไว้ตั้งแต่ **2026-08-12**:

> *"on 2026-08-12 a `window.localStorage` whose `getItem` was not a function turned five test suites red …
> a test environment can supply a partial object"*

และ `PipelineBoardConfirmOrder.spec.ts` ของ agent portal เขียนกำกับไว้ว่าจงใจไม่เรียก `localStorage.clear()`

**ADR-003 ระบุว่า `frontend-admin/src/utils/safeStorage.js` เป็นสำเนาที่ตั้งใจให้มี — ให้ sync กัน**
ไฟล์นั้นมีอยู่จริงใน admin แล้ว แต่**มีแค่ `useI18n.js` กับ `useFontSize.js` ที่ใช้**
ส่วนที่เหลือของ admin ยังเรียก `localStorage` ตรง ๆ — บทเรียน 2026-08-12 ถูกใช้แค่ครึ่งเดียว

## 4. ทางแก้ที่ **ถูกทิ้งไป** และเหตุผล

ตอนแรกผมเขียน in-memory `Storage` ใส่ `vitest.setup.ts` ให้เทสต์ผ่านทุกเครื่อง — **แล้วถอยออก**

1. มัน**กลบ property ที่โปรเจกต์ตั้งใจพึ่ง** — environment ที่ Storage พังคือสิ่งที่จับบั๊กคลาสนี้ได้ตั้งแต่ 2026-08-12
2. ที่สำคัญกว่า มัน**ไม่แก้บั๊ก production เลย** `AcademyManagementView.vue` อ่าน `localStorage` ตอน `setup()`
   → Safari โหมดส่วนตัว / sandboxed iframe / ผู้ใช้ปิด site data = **จอขาวทั้งหน้า** ไม่ใช่แค่ลืมค่าที่เคยกด

เทสต์ที่แดงบนเครื่อง human กำลังรายงานบั๊กจริง การทำให้มันเขียวโดยไม่แก้บั๊กคือการปิดปากมัน

## 5. ทางแก้จริง — ให้ทุกคนผ่าน `safeStorage`

`safeStorage` เช็ค **ทั้ง** `typeof getItem === 'function'` และ `setItem` ก่อนใช้ ครอบ `try` ตั้งแต่
ตอนอ่าน property (sandboxed iframe โยนตั้งแต่ตรงนั้น) อ่านไม่ได้คืน `null` เขียนไม่ได้ก็เงียบ

| ไฟล์ | เดิม | ผลถ้าพัง |
|---|---|---|
| `frontend-admin/.../AcademyManagementView.vue` | `localStorage.getItem` ใน `setup()` | **จอขาว** — ต้นเหตุ 15 เทสต์แดง |
| `frontend-admin/.../CommissionPlansView.vue` | เหมือนกัน | **จอขาว** (ไม่มี spec เลยไม่มีใครเห็น) |
| `frontend-admin/src/stores/activeCompany.ts` | `localStorage.setItem` ไม่มี try | **คลิกเลือกบริษัทแล้วพัง** |
| `frontend-admin/src/utils/activeCompanyStorage.ts` | try/catch ของตัวเอง | ไม่พัง แต่เขียนตรรกะซ้ำ |
| `HeroHeader.vue` (**ทั้งสองแอป**) | try/catch มือ | ไม่พัง — แต่ `try` กันแค่ตัวที่ **โยน** ไม่กันตัวที่ **มีอยู่แต่ใช้ไม่ได้** ซึ่งคือเคสที่เกิดจริง |

`seenAnnouncements.ts` ไม่แตะ — try/catch ของมันครอบ `JSON.parse` ที่พังได้อีกแบบด้วย และมันอ่านแบบ lazy อยู่แล้ว

ส่วน spec สองไฟล์ที่เรียก `localStorage.clear()` ตรง ๆ ถูกลบทิ้งพร้อมคำอธิบายแบบเดียวกับ
`PipelineBoardConfirmOrder.spec.ts` ของ agent portal — ไม่มีอะไรในสองสวีตนั้นเขียน Storage เลย

## 6. เก็บกวาดระหว่างทาง — `vue-tsc --build` แดงอยู่ทั้งสองแอป

```
useAuthenticatedMedia.spec.ts(26,3): error TS2578: Unused '@ts-expect-error' directive.
```

มาจาก TASK-223/224 ของผมเอง **`vue-tsc --noEmit -p tsconfig.app.json` มองไม่เห็น เพราะ config นั้นไม่รวมไฟล์เทสต์**
— `npm run type-check` (`vue-tsc --build`) ต่างหากที่เห็น ผมเคยเช็คแต่ตัวแรกจึงพลาด
`@ts-expect-error` นั้นคุม fallback `globalThis.document ?? { cookie: '' }` และ TS บอกว่ามัน **ไม่มีทางถูกใช้**
(`environment: 'jsdom'` ถูก pin ไว้ทั้งโปรเจกต์) — ลบ fallback ทิ้ง ไม่ใช่ลบแค่ directive

## 7. ตรวจแล้ว

| | ปกติ | **จำลอง Storage พังแบบเครื่อง human** |
|---|---|---|
| frontend-admin | 106 passed (9 files) | **106 passed** |
| frontend | 132 passed (13 files) | **132 passed** |

`vue-tsc --build` และ `eslint .` ผ่านทั้งสองแอป (type-check เคยแดงก่อนหน้านี้ — ดู §6)

## 8. ยังค้าง

- **สาเหตุที่ macOS ให้ `localStorage` เป็น object เปล่ายังไม่ทราบแน่ชัด** — เวอร์ชัน package ตรงกันหมด
  ผมพิสูจน์ได้แค่ว่า *เกิดอะไรขึ้น* และทำให้โค้ดทนต่อมันได้ ไม่ได้พิสูจน์ว่า *ทำไม jsdom ถึงให้ค่านั้น*
  ตอนนี้ไม่กระทบใครแล้ว แต่ยังไม่ใช่คำตอบที่ครบ
- `safeStorage.js` ไม่มี `removeItem` — ยังไม่มีใครต้องใช้ จึงยังไม่เพิ่ม
