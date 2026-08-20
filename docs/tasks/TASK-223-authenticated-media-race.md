# TASK-223 — รูปสินค้าแสดงบ้างไม่แสดงบ้าง (Safari, production)

**Status:** implemented · **พิสูจน์ด้วยเทสต์ที่ fail บนโค้ดเดิมและ pass บนโค้ดใหม่**
**Owner:** ag-lead · **คำขอ:** human — "ผมทำสอบใน safari รูปสินค้าแสดงบ้างไม่แสดงบ้าง"

---

## 1. ไม่ใช่ไฟล์เสีย — ตรวจ production แล้ว

ยิงตรงไปที่ media ทุกตัวของบริษัท Thai Life:

```
GENESENN Health Tr m#1 image/cover primary=true -> 200 image/jpeg 345,301 B
GENESENN Health Tr m#2 image/cover primary=true -> 200 image/jpeg 137,131 B
```

**ไฟล์ครบ สิทธิ์ถูก ขนาดจริง** — ปัญหาอยู่ฝั่ง client

## 2. ต้นเหตุ: race ใน `useAuthenticatedMedia`

รูปสินค้าอยู่บน disk `local` (ส่วนตัว) สตรีมผ่าน route ที่ต้องมี session cookie
`<img src>` ธรรมดาแนบ cookie ไม่ได้ แอปจึง `fetch()` แล้วแปลงเป็น **blob URL**

**ตัวจุดชนวนคือการ์ด 2 ใบที่แสดงรูปเดียวกันพร้อมกัน** ซึ่งหน้าสินค้าทำตลอด:
การ์ด "แนะนำสำหรับคุณ" ด้านบน กับ grid ด้านล่าง เป็นสินค้าตัวเดียวกันได้

ลำดับที่พัง:

1. การ์ด A mount → เริ่ม fetch URL X · cache ยังว่าง
2. การ์ด B mount ด้วย X เดียวกัน → cache **ยังว่างอยู่** (fetch ของ A ยังไม่ resolve)
   → **ยิง fetch ซ้ำอีกครั้ง** ไม่มีการรวม request
3. ทั้งคู่ resolve → `createObjectURL` คนละใบ → `objectUrlCache.set(X, ...)`
   ของตัวหลัง **ทับ** ตัวแรก → A ถือ blob ที่ cache ไม่รู้จักแล้ว (ทั้ง leak ทั้งค้าง)
4. **refCount ถูก `+1` หลัง `await`** → ระหว่างที่ fetch ยังไม่เสร็จ count เป็น 0
   → `release()` ที่เกิดในช่วงนั้น **revoke blob ที่การ์ดอีกใบกำลังใช้อยู่**

ข้อ 4 คือคำว่า "บ้าง" ใน "แสดงบ้างไม่แสดงบ้าง"

> Safari เห็นอาการชัดกว่า Chrome — ไม่ใช่เพราะ Safari ผิด แต่เพราะ blob URL ที่ถูก
> revoke แล้วคือ handle ที่ใช้ไม่ได้ทุกเบราว์เซอร์ Safari แค่เลิกยอมรับเร็วกว่า

## 3. แก้อะไร

`useAuthenticatedMedia.ts` — **แก้ทั้งสองแอป** (frontend + frontend-admin เก็บสำเนาคู่กันตาม CI-001/CI-002)

| แก้ | ผล |
|---|---|
| เพิ่ม `inFlight` map — cache **promise** ไม่ใช่แค่ผลลัพธ์ | การ์ด 2 ใบที่ขอรูปเดียวกันพร้อมกัน ใช้ request เดียว blob เดียว |
| `retain()` **ก่อน** `await` แล้วค่อย `release()` ตัวเก่า | โหลด URL เดิมซ้ำ count ไม่มีทางตกเป็น 0 → ไม่ revoke ของที่คนอื่นใช้อยู่ |
| cache เขียนครั้งเดียว ไม่ทับ | ไม่มี blob กำพร้าที่ revoke ไม่ได้ |
| ถ้า refCount = 0 ตอน fetch เสร็จ → revoke ทิ้งทันที ไม่ cache | คนที่ขอ unmount ไปหมดแล้ว ไม่แจก handle ที่ไม่มีใครเก็บกวาด |
| เช็ค `currentTracked === url` ก่อนเขียนผล | load() รอบใหม่ที่แซงมา ไม่ถูกทับด้วยผลของรอบเก่า |

## 4. พิสูจน์แล้ว ไม่ใช่เดา

เขียน spec 5 ข้อ แล้วรันกับ**ทั้งสองเวอร์ชัน**:

```
=== โค้ดเดิม
  × makes ONE request when two components ask for the same url at once
  × gives both components the SAME object url, and creates only one blob
  × does not revoke a blob a second component is still showing
  Tests  3 failed | 2 passed (5)

=== โค้ดใหม่
  Tests  5 passed (5)
```

ข้อที่ 3 คืออาการของ human เป๊ะ ๆ

> **วิธีรัน:** sandbox รัน vitest ของโปรเจกต์ไม่ได้ (rolldown native binding เป็น
> macOS build) ผมจึงติดตั้ง `vue + vitest` เปล่า ๆ ในคลาวด์แล้วรัน composable
> ตัวจริงกับ spec ตัวจริง · ไฟล์ spec ที่ commit ไปวางอยู่ใน `__tests__` ของทั้งสองแอป
> **กรุณารัน `npm run test:unit` ยืนยันอีกครั้งบนเครื่องคุณ**

## 5. ที่ยังไม่ได้แก้

- ~~ไม่มี retry เมื่อ fetch พลาด~~ → **แก้แล้วใน TASK-224** (ดู `docs/tasks/TASK-224-…md`)
- **ไม่ได้ลด request รวม** — หน้าที่มีสินค้าเยอะยังยิงเท่าจำนวนรูปที่ไม่ซ้ำ
  ถ้าในอนาคตเจอ 429 ค่อยคุยเรื่อง batch/รูป public
