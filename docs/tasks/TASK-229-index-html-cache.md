# TASK-229 — deploy ขึ้นแล้วแต่เบราว์เซอร์ยังรันโค้ดเก่า (อัปโหลดยังติด 200 MB)

**Status:** diagnosed + fixed · **Owner:** ag-lead
**คำขอ:** human — *"ตอนนี้ที่ Production ผมก็ยัง Upload คลิปได้ไม่เกิน 200 mb ติดที่ไหนวิเคราะห์และเสนอแนวทางแก้ปัญหา ขอวิธีเช็คด้วย"*

---

## 1. ข้อสรุป: **โค้ดไม่ผิด และ deploy ขึ้นไปแล้วจริง**

TASK-226 ทำงานถูกต้องทั้งหมด สิ่งที่ผิดคือ **เบราว์เซอร์ยังโหลด JS ตัวก่อน deploy อยู่**

## 2. หลักฐานที่ตัดจบทีละชั้น

ตรวจผ่าน Chrome ที่เชื่อมกับ session นี้ ในหน้า `admin.partner.syncvision.io` ที่ล็อกอินจริง:

| ตรวจ | ผล | สรุป |
|---|---|---|
| `localStorage['sva.admin.activeCompanyId']` | `"1"` | ✅ เลือกบริษัทไว้แล้ว ไม่ใช่ "ทุกบริษัท" |
| `GET /me` | `role: super_admin` | ✅ |
| `GET /video-processing-settings?company_id=1` | `max_upload_mb: 300` | ✅ **ค่าในฐานข้อมูลถูก** (id 2–5 ยังเป็น 200 ตามค่า default) |
| chunk ที่มี `/uploads/init` ใน bundle ที่**กำลังรัน** | `Icon-MiqkDfgl.js` — มี `size_bytes` แต่ **ไม่มี `company_id`** | ❌ **โค้ดเก่า** |
| chunk เดียวกันใน bundle ที่**อยู่บนเซิร์ฟเวอร์** | `Icon-CoUDrwZ_.js` — มี `company_id` และ `sva.admin.activeCompanyId` | ✅ โค้ดใหม่อยู่บนเซิร์ฟเวอร์แล้ว |
| `index.html` บนเซิร์ฟเวอร์ชี้ไปที่ | `index-Ds3ax1IP.js` | |
| หน้าที่เปิดอยู่โหลดจริง | `index-8p9WGQoD.js` | ❌ **คนละไฟล์** |

เบราว์เซอร์ถือ `index.html` เวอร์ชันก่อน deploy ไว้ จึงไป**ขอไฟล์ hash เก่า**ต่อไปเรื่อย ๆ
เซิร์ฟเวอร์ก็ส่งให้ เพราะไฟล์เก่ายังอยู่ครบ — ทุกอย่าง "สำเร็จ" หมด แต่เป็นโค้ดคนละรุ่น

## 3. สาเหตุราก — `index.html` ไม่มี `Cache-Control`

```
GET /index.html                → cache-control: (ไม่มี)   last-modified: 20 Aug 2026 08:54
GET /assets/index-Ds3ax1IP.js  → cache-control: public, max-age=604800
```

ไฟล์ใน `assets/` ถูก Vite ใส่ hash ไว้ จึงแคชยาว ๆ ได้อย่างปลอดภัย
แต่ **`index.html` คือสิ่งเดียวที่บอกว่า hash ปัจจุบันคืออะไร** และมันไม่มี header กำกับเลย

เมื่อไม่มี `Cache-Control` เบราว์เซอร์จะใช้ **heuristic caching** — เดาอายุแคชเองจาก `Last-Modified`
(ปกติ ~10% ของอายุไฟล์) นั่นแปลว่า *เบราว์เซอร์เป็นคนตัดสินใจ* ว่าจะเห็นของใหม่เมื่อไร ไม่ใช่เรา

**ผลลัพธ์: deploy สำเร็จทุกครั้ง แต่ผู้ใช้ไม่เห็นของใหม่จนกว่าจะบังเอิญ hard refresh**
บั๊กนี้ไม่ได้กระทบแค่ TASK-226 — มันกลบ *ทุก* การ deploy ฝั่ง frontend ที่ผ่านมา

## 4. ทางแก้เฉพาะหน้า (ทำไปแล้ว)

สั่ง reload ข้ามแคชในแท็บ admin → ตอนนี้โหลด `index-Ds3ax1IP.js` แล้ว
ผู้ใช้คนอื่นที่ยังค้างอยู่: **`Cmd+Shift+R`** (Mac) / **`Ctrl+F5`** (Windows)

## 5. ทางแก้ถาวร — `.htaccess` ทั้งสองแอป

```apache
<IfModule mod_headers.c>
  <FilesMatch "\.html$">
    Header set Cache-Control "no-cache, must-revalidate"
  </FilesMatch>
</IfModule>
```

- ใส่ใน `frontend/public/.htaccess` และ `frontend-admin/public/.htaccess`
  Vite คัดลอก `public/` ลง `dist/` ทุกครั้ง จึงติดไปกับ `npm run deploy` เองอัตโนมัติ
  (สำคัญ: `deploy.sh` ใช้ `rsync --delete` — ไฟล์ที่ไปวางมือบนเซิร์ฟเวอร์จะถูกลบทิ้งทุกรอบ
  ต้องอยู่ใน `public/` เท่านั้นถึงจะรอด)
- **`no-cache` ไม่ได้แปลว่า "ห้ามเก็บ"** แต่แปลว่า "ต้องถามเซิร์ฟเวอร์ก่อนใช้"
  เซิร์ฟเวอร์ส่ง `Last-Modified` อยู่แล้ว ถ้าไม่เปลี่ยนก็จบที่ 304 — แทบไม่มีต้นทุน
- **ไม่แตะนโยบายของ `assets/`** ที่ 7 วัน — ไฟล์พวกนั้นมี hash กำกับ แคชยาวคือสิ่งที่ถูกแล้ว

## 6. วิธีเช็คซ้ำในอนาคต (ใช้ได้ทุกครั้งที่สงสัยว่า "deploy ขึ้นหรือยัง")

วางใน Console ของหน้าที่สงสัย:

```js
const loaded = [...document.querySelectorAll('script[src]')].map(s => s.src.split('/').pop())
const html = await (await fetch(location.origin + '/index.html', { cache: 'no-store' })).text()
const onServer = html.match(/assets\/[A-Za-z0-9_.\-]+\.js/g)
console.log({ loaded, onServer })
```

**ถ้าสองอันไม่ตรงกัน = เบราว์เซอร์รันของเก่า** ไม่ใช่บั๊กของโค้ด

## 7. บทเรียนที่ควรบันทึก

ผมใช้เวลาไล่หาสาเหตุนี้หลายรอบ โดยตั้งสมมติฐานผิดไป 2 ข้อก่อนหน้า
(ตัวเลือกบริษัทเป็น "ทุกบริษัท", ค่าในฐานข้อมูลไม่ใช่ 300) ทั้งที่**เช็คที่ถูกต้องคือ
เทียบ hash ของไฟล์ที่โหลดกับที่อยู่บนเซิร์ฟเวอร์** ซึ่งใช้เวลา 10 วินาที

หลังจากนี้ เวลา "แก้แล้วแต่ production ยังเหมือนเดิม" ให้เช็คข้อ 6 **เป็นอย่างแรกเสมอ**
ก่อนจะไปสงสัยตรรกะของโค้ด

## 8. ยังไม่ได้ทำ

- **ยังไม่ได้ยืนยันว่าอัปโหลด >200 MB ผ่านจริงบน production** — ต้องให้ human ลองไฟล์จริง
  ทุกชั้นที่ตรวจได้ชี้ว่าจะผ่าน แต่นั่นยังไม่ใช่การพิสูจน์
- `.htaccess` ใหม่จะมีผลก็ต่อเมื่อ deploy รอบถัดไป
- บริษัท id 2–5 ยังเป็น 200 MB ตาม default — ถ้าตั้งใจให้ต่างจากนี้ต้องไปตั้งเอง (BR-7)
