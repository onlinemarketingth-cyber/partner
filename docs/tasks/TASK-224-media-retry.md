# TASK-224 — รูปโหลดพลาดแล้วลองใหม่ได้ (ทั้งอัตโนมัติและกดเอง)

**Status:** implemented · **เทสต์ 5 ข้อใหม่ fail บนโค้ดก่อนหน้า pass บนโค้ดนี้**
**Owner:** ag-lead · **คำขอ:** human — "ไม่มี retry เมื่อ fetch พลาด … แก้อย่างไร ทำอย่างไร ถึงจะใช้งานได้"

---

## 1. ปัญหา

TASK-223 แก้เรื่อง blob ถูก revoke ผิดตัวไปแล้ว แต่เหลืออีกอย่าง:

**fetch พลาดครั้งเดียว = สามเหลี่ยมแดงค้างตลอดไป** จนกว่า component จะ remount
(เลื่อนออกแล้วเลื่อนกลับ หรือ reload หน้า)

บนมือถือ สาเหตุที่พบบ่อยที่สุด**ไม่ใช่ไฟล์เสีย** แต่คือ 1 ใน 12 request หลุดการเชื่อมต่อ

## 2. แก้ 2 ชั้น

### ชั้นที่ 1 — retry อัตโนมัติ (เฉพาะที่ควร retry)

```
พยายาม 3 ครั้ง · หน่วง 400ms แล้ว 1200ms
```

**แยก "อาการสะดุด" ออกจาก "คำตอบ" — สำคัญกว่าที่คิด**

| สถานะ | retry? | เหตุผล |
|---|---|---|
| เชื่อมต่อหลุด (fetch reject) | ✅ | สิ่งที่ควร retry ที่สุด |
| `408` `429` `5xx` | ✅ | เซิร์ฟเวอร์สะดุด / โดน rate limit |
| `404` `403` `401` | ❌ | **เป็นคำตอบ ไม่ใช่ความผิดพลาด** |

ถ้า retry 404 ด้วย: หน้า grid ที่มีรูปหาย 12 ใบ จะยิง **36 request** เพื่อได้คำตอบเดิม

**ทำไมแค่ 3 ครั้ง:** หน้าสินค้าหนึ่งหน้ามีรูปได้เป็นสิบ · ทุกครั้งที่เพิ่ม
คูณด้วยจำนวนรูป · 2 retry สั้น ๆ กู้อาการสะดุดได้ โดยไม่เปลี่ยนเซิร์ฟเวอร์ที่ล่มจริง
ให้กลายเป็นการถล่มซ้ำ

**หยุดทันทีถ้าไม่มีใครรอแล้ว** — เช็ค `stillWanted()` ระหว่างครั้ง: การ์ดที่เลื่อนพ้นจอ
หรือเปลี่ยน url แล้ว จะไม่วิ่ง retry chain ต่อให้เสียเปล่า

### ชั้นที่ 2 — ปุ่ม "ลองใหม่" ให้กดเอง

สามเหลี่ยมแดงเดิมกลายเป็น **ปุ่มกดได้**:

```
   ⟳
ลองใหม่
```

ครอบคลุมสิ่งที่ retry อัตโนมัติจงใจไม่ทำ:
- **404 ที่แอดมินอัปโหลดใหม่ไปแล้ว**
- **403 ที่หายไปหลังล็อกอินใหม่**
- ผู้ใช้ที่แค่อยากลองอีกที โดยไม่ต้อง reload ทั้งหน้า

> `type="button"` สำคัญ — component นี้ถูกเรนเดอร์ในฟอร์มแก้ไขสินค้าด้วย
> `<button>` เปล่า ๆ จะ submit ฟอร์มทิ้ง

### ข้อความ error ตรงกับสิ่งที่เกิดขึ้น

| กรณี | ข้อความ |
|---|---|
| 404 / 403 | **"ไม่พบไฟล์สื่อนี้"** |
| อื่น ๆ (หลัง retry ครบแล้ว) | **"โหลดสื่อไม่สำเร็จ"** |

เดิมทุกกรณีขึ้นข้อความเดียวกัน ทำให้แยกไม่ออกว่า "ไฟล์หาย" กับ "เน็ตสะดุด"

## 3. พิสูจน์แล้ว

```
=== โค้ดก่อนหน้า (TASK-223)
  × retries a transient failure and succeeds on the second attempt
  × gives up after three attempts on a server that stays down
  × does NOT retry a 404
  × retries a dropped connection (fetch itself rejecting)
  × retry() recovers after a permanent failure that has since been fixed
  Tests  5 failed | 5 passed (10)

=== โค้ดนี้
  Tests  10 passed (10)
```

ใช้ fake timers จึงรันจบใน 0.4 วินาที ไม่ได้นอนรอจริง

## 4. ไฟล์

แก้ **ทั้งสองแอป** (frontend + frontend-admin เก็บสำเนาคู่กัน CI-001/CI-002):

| ไฟล์ | |
|---|---|
| `composables/useAuthenticatedMedia.ts` | `MediaFetchError` ที่พก status · `attemptWithRetries()` · คืน `retry()` เพิ่ม |
| `design-system/components/AuthenticatedMedia.vue` | placeholder ตอน error กลายเป็นปุ่ม |
| `composables/__tests__/useAuthenticatedMedia.spec.ts` | +5 test (รวมเป็น 10) |

## 5. ต้องรันยืนยัน

```bash
cd frontend && npm run test:unit
cd ../frontend-admin && npm run test:unit
```

sandbox รัน vitest ของโปรเจกต์ไม่ได้ (rolldown เป็น macOS build) — ผมรันด้วย
`vue + vitest` เปล่า ๆ ในคลาวด์กับ composable ตัวจริง
