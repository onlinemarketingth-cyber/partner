"""
Builds the QA checklist workbook for the six UAT commission-plan tenants.

The figures here are NOT copied out of a run of the seeder — they are the
same hand-derived expectations that UatSeedCommissionPlansTest asserts, so a
disagreement between this sheet and the system is a finding rather than a
tautology. Keep the two in step: if a fixture changes in the command, it
changes in the test and here.
"""

from openpyxl import Workbook
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.utils import get_column_letter

FONT = "Arial"

NAVY = "1E2A54"       # brand-600
SLATE = "475569"
AMBER = "FEF3C7"
GREEN = "DCFCE7"
GREY = "F1F5F9"
WHITE = "FFFFFF"

thin = Side(style="thin", color="CBD5E1")
box = Border(left=thin, right=thin, top=thin, bottom=thin)


def h1(ws, cell, text):
    ws[cell] = text
    ws[cell].font = Font(name=FONT, size=15, bold=True, color=NAVY)


def note(ws, cell, text):
    ws[cell] = text
    ws[cell].font = Font(name=FONT, size=10, color=SLATE)
    ws[cell].alignment = Alignment(wrap_text=True, vertical="top")


def header_row(ws, row, headers, widths):
    for i, (head, width) in enumerate(zip(headers, widths), start=1):
        c = ws.cell(row=row, column=i, value=head)
        c.font = Font(name=FONT, size=10, bold=True, color=WHITE)
        c.fill = PatternFill("solid", fgColor=NAVY)
        c.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)
        c.border = box
        ws.column_dimensions[get_column_letter(i)].width = width
    ws.row_dimensions[row].height = 28


def body_row(ws, row, values, money_cols=(), fill=None):
    for i, v in enumerate(values, start=1):
        c = ws.cell(row=row, column=i, value=v)
        c.font = Font(name=FONT, size=10)
        c.border = box
        c.alignment = Alignment(vertical="center", wrap_text=True)
        if i in money_cols:
            c.number_format = '#,##0.00'
            c.alignment = Alignment(horizontal="right", vertical="center")
        if fill:
            c.fill = PatternFill("solid", fgColor=fill)


# ── where each setting actually lives on screen ──────────────────────────────
#
# 2026-09-22, owner: "ผมไปดูค่าที่ setup ค่าคอม ของแผน stairstep ทำไมถึงไม่
# เหมือนกัน".
#
# The first version of "ขั้นที่ 1" was written from what each plan MEANS, not
# from the screen an admin opens. So it listed a seller rate that lives on
# step 3 next to a matrix width that lives on step 2, named ranks without the
# "UAT " prefix they actually carry, and listed "สายงาน" — which is not on
# this screen at all — as if it were a field to check.
#
# Every row below now names a control that exists, spelled as the screen
# spells it, grouped under the section it sits in. Anything that is NOT on
# this screen goes under S_OFF and says where it is instead.

S_CHIP = "ขั้นที่ 2 · แถวชิปเลือกแผน"
S_BASIS = "ขั้นที่ 2 · การ์ด “ค่าแนะนำคิดจากอะไร”"
S_PV = "ขั้นที่ 2 · ตาราง “PV ของแต่ละสินค้า”"
S_LADDER = "ขั้นที่ 2 · ข้างแผนภูมิ “อัตราหัวหน้าทีมแต่ละชั้น”"
S_STEP3 = "ขั้นที่ 3 · สมาชิกผู้ขายได้กี่เปอร์เซ็นต์"
S_STEP4 = "ขั้นที่ 4 · ส่วนเพิ่มเติม"
S_OFF = "ไม่ได้อยู่บนหน้านี้ — ตรวจที่อื่น"

# Step 4 reads almost the same on every plan, so it is written once — but
# SPLIT either side of 4.3, because 4.3's value is the one line that differs
# per plan and the sheet must list the numbered boxes in the order the screen
# shows them. A single list with 4.3 appended printed 4.3 after 4.8.
STEP4_HEAD = [
    (S_STEP4, "4.1 เงินของหัวหน้าทีมมาจากไหน", "บริษัทจ่ายเพิ่ม",
     "ป้าย “ตอนนี้: บริษัทจ่ายเพิ่ม” ที่หัวการ์ด · เป็นค่าเริ่มต้นของระบบ คำสั่ง seed ไม่ได้แตะ"),
    (S_STEP4, "4.2 จ่ายขึ้นไปกี่ชั้น", "กล่องนี้ต้องไม่ขึ้นเลย",
     "มีเฉพาะบริษัทที่ใช้แผน Unilevel — แผนอื่นจำกัดชั้นด้วยค่าของตัวเอง"),
]

STEP4_TAIL = [
    (S_STEP4, "4.7 ยอดขั้นต่ำในการเบิก", "เว้นว่าง", "เว้นว่าง = ไม่มีขั้นต่ำ"),
    (S_STEP4, "4.8 ภาษีหัก ณ ที่จ่าย", "ไม่หัก (เว้นว่าง)",
     "มีบริษัทเดียวในชุดนี้ที่หัก คือ Affiliate"),
]


# ── the six plans ────────────────────────────────────────────────────────────
# who, source, rate shown on screen, expected baht. Derived by hand from the
# plan rule and the fixtures; mirrored in UatSeedCommissionPlansTest.

PLANS = [
    {
        "tab": "1 Unilevel",
        "title": "Unilevel — จ่ายขึ้นไปทีละชั้น",
        "slug": "uat-plan-unilevel",
        "basis": "ราคาขาย ฿10,000.00",
        "rule": "ผู้ขายได้อัตราของตัวเอง แล้วหัวหน้าแต่ละชั้นเหนือขึ้นไปได้ตามอัตราของชั้นนั้น "
                "โหมด additive = บริษัทจ่ายเพิ่มให้หัวหน้า ไม่หักจากผู้ขาย",
        "setup": [
            (S_CHIP, "แผนที่เลือกอยู่", "Unilevel",
             "ชิปของแผนอื่นต้องกดไม่ได้และมีรูปกุญแจ เพราะบริษัทนี้ขายไปแล้ว (ดูชีต 7)"),
            (S_BASIS, "ฐานการคำนวณ", "ราคาขาย",
             "ปุ่ม “ราคาขาย” ต้องมีเครื่องหมายถูกและป้าย “ค่าเริ่มต้น” · ทั้งสองปุ่มกดไม่ได้"),
            ("ขั้นที่ 2 · กล่องใต้การ์ดฐานการคำนวณ", "ข้อความประจำแผน",
             "“Unilevel ไม่มีค่าตั้งระดับบริษัทให้กรอกในขั้นนี้ …”",
             "แผนนี้เป็นแผนเดียวที่ไม่มีฟอร์มค่าตั้งระดับบริษัท — ไม่ใช่ของหาย"),
            (S_LADDER, "ชั้น 1 · หัวหน้าโดยตรง", "5",
             "ช่องกรอกเป็นตัวเลขล้วน ไม่มีเครื่องหมาย % · ขวามือต้องขึ้น 500.00 บาท"),
            (S_LADDER, "ชั้น 2", "3", "ขวามือต้องขึ้น 300.00 บาท"),
            (S_LADDER, "ชั้น 3", "1", "ขวามือต้องขึ้น 100.00 บาท"),
            (S_LADDER, "ป้าย “ยังไม่ได้บันทึก”", "ต้องไม่ขึ้น",
             "ขึ้นเฉพาะตอนที่แก้ค่าแล้วยังไม่กดบันทึก — ถ้าขึ้นทั้งที่ยังไม่แตะอะไร แปลว่าค่าที่โหลดมาไม่ตรงกับที่บันทึกไว้"),
            (S_STEP3, "ค่าเริ่มต้นทั้งบริษัท", "10.00%",
             "ชั้นที่ 3 ของบันไดลำดับ · ชุดนี้ไม่ได้ตั้งอัตราระดับสินค้าหรือหมวดหมู่ไว้เลย"),
            (S_STEP4, "4.1 เงินของหัวหน้าทีมมาจากไหน", "บริษัทจ่ายเพิ่ม",
             "ป้าย “ตอนนี้: บริษัทจ่ายเพิ่ม” ที่หัวการ์ด · เป็นค่าเริ่มต้นของระบบ คำสั่ง seed ไม่ได้แตะ"),
            (S_STEP4, "4.2 จ่ายขึ้นไปกี่ชั้น", "เว้นว่าง = ทั้งสาย",
             "ถ้ากรอกเลขน้อยกว่า 3 ต้องมีแถบเหลืองขึ้นที่ขั้นที่ 2 บอกว่าชั้นล่างสุดจะไม่ได้เงิน"),
            (S_STEP4, "4.3 อัตราหัวหน้าทีม · ค่าเริ่มต้นทั้งบริษัท", "ไม่มีรายการ",
             "ว่างตรงนี้ถูกต้อง — 5/3/1% เป็นอัตรา “แยกรายชั้น” จึงไปแสดงที่ขั้นที่ 2 ข้างแผนภูมิแทน"),
            (S_STEP4, "4.7 ยอดขั้นต่ำในการเบิก", "เว้นว่าง", "เว้นว่าง = ไม่มีขั้นต่ำ"),
            (S_STEP4, "4.8 ภาษีหัก ณ ที่จ่าย", "ไม่หัก (เว้นว่าง)",
             "มีบริษัทเดียวในชุดนี้ที่หัก คือ Affiliate"),
            (S_OFF, "สายงาน (ใครเป็นหัวหน้าของใคร)",
             "ผู้ขาย → หัวหน้าชั้น 1 → หัวหน้าชั้น 2 → หัวหน้าชั้น 3",
             "ไม่ได้ตั้งที่หน้าค่าแนะนำ — ดูที่เมนูจัดการผู้ใช้ระบบ ช่อง “หัวหน้า” ของแต่ละคน"),
        ],
        "rows": [
            ("UAT ผู้ขาย", "direct", "10.00%", 1000.00, "10% ของ ฿10,000"),
            ("UAT หัวหน้าชั้น 1", "override", "5.00%", 500.00, "5% ของ ฿10,000"),
            ("UAT หัวหน้าชั้น 2", "override", "3.00%", 300.00, "3% ของ ฿10,000"),
            ("UAT หัวหน้าชั้น 3", "override", "1.00%", 100.00, "1% ของ ฿10,000"),
        ],
        "watch": [
            "ผู้ขายต้องได้เต็ม ฿1,000 — ถ้าน้อยกว่านี้ แปลว่าระบบไปหักค่าหัวหน้าจากผู้ขาย ซึ่งผิดโหมด additive",
            "ต้องมีครบ 4 แถว — ขาดแถวไหนแปลว่าหัวหน้าคนนั้นถูกข้าม (มักเพราะยังไม่ผ่าน Basic ตาม BR-1)",
        ],
    },
    {
        "tab": "2 Binary",
        "title": "Binary — จ่ายจากขาที่น้อยกว่า และจ่ายเป็นรอบ",
        "slug": "uat-plan-binary",
        "basis": "ราคาขาย ฿10,000.00",
        "rule": "ผู้ขายแต่ละคนได้อัตราของตัวเองทันทีที่ปิดการขาย ส่วนผู้สนับสนุนจะได้เมื่อ "
                "รอบจับคู่ทำงาน โดยคิดจากยอดขาที่น้อยกว่า",
        "setup": [
            (S_CHIP, "แผนที่เลือกอยู่", "Binary", "ชิปของแผนอื่นต้องกดไม่ได้และมีรูปกุญแจ"),
            (S_BASIS, "ฐานการคำนวณ", "ราคาขาย", "ทั้งสองปุ่มกดไม่ได้"),
            ("ขั้นที่ 2 · ฟอร์ม “ค่าตั้งระดับบริษัทของแผน Binary”",
             "รูปแบบอัตรา Matched", "% ของยอด Matched", "อีกตัวเลือกคือ “จำนวนคงที่ (บาท)”"),
            ("ขั้นที่ 2 · ฟอร์ม “ค่าตั้งระดับบริษัทของแผน Binary”",
             "อัตรา (%)", "10", "ชื่อช่องเปลี่ยนเป็น “จำนวน (บาท)” ถ้าสลับรูปแบบข้างบน"),
            ("ขั้นที่ 2 · ฟอร์ม “ค่าตั้งระดับบริษัทของแผน Binary”",
             "รอบคำนวณ", "รายสัปดาห์", "อีกสองตัวเลือกคือ ทุก 2 สัปดาห์ / รายเดือน"),
            ("ขั้นที่ 2 · ฟอร์ม “ค่าตั้งระดับบริษัทของแผน Binary”",
             "เพดานจ่าย/รอบ (บาท, ว่าง = ไม่จำกัด)", "เว้นว่าง", ""),
            ("ขั้นที่ 2 · ฟอร์ม “ค่าตั้งระดับบริษัทของแผน Binary”",
             "ยกยอดที่ไม่ Matched ไปรอบถัดไป", "ติ๊กไว้", "ช่องติ๊ก ไม่ใช่ช่องกรอก"),
            ("ขั้นที่ 2 · “ประวัติรอบ Matching (อ่านอย่างเดียว)”",
             "ก่อนรันคำสั่งรอบจับคู่", "“ยังไม่มีรอบ Matching”", "ว่างตรงนี้ถูกต้อง"),
            ("ขั้นที่ 2 · “ประวัติรอบ Matching (อ่านอย่างเดียว)”",
             "หลังรันคำสั่งรอบจับคู่",
             "1 แถว · ซ้าย ฿10,000.00 · ขวา ฿10,000.00 · Matched ฿10,000.00 · ยกยอด ฿0.00 · ป้าย “จ่ายแล้ว”",
             "ถ้าป้ายยังเป็น “ไม่มีค่าแนะนำ” แปลว่ารอบทำงานแล้วแต่ไม่ได้ลงบัญชี — เป็นสิ่งที่ต้องรายงาน"),
            (S_STEP3, "ค่าเริ่มต้นทั้งบริษัท", "10.00%", "ผู้ขายแต่ละขาได้จากตรงนี้ ไม่ใช่จากอัตรา Matched"),
            *STEP4_HEAD,
            (S_STEP4, "4.3 อัตราหัวหน้าทีม · ค่าเริ่มต้นทั้งบริษัท", "ไม่มีรายการ",
             "แผนนี้จ่ายชั้นบนผ่านรอบจับคู่ ไม่ได้ใช้อัตราหัวหน้าทีม — ว่างตรงนี้ถูกต้อง"),
            *STEP4_TAIL,
            (S_OFF, "ขาซ้าย / ขาขวา ของแต่ละคน", "ขาซ้าย 1 คน · ขาขวา 1 คน ใต้ผู้สนับสนุน",
             "ตั้งที่เมนูจัดการผู้ใช้ระบบ — ตัวแทนที่ไม่ได้ตั้งขาจะไม่ส่งยอดขึ้นไปเลยและไม่มีคำเตือน"),
        ],
        "rows": [
            ("UAT ขาซ้าย", "direct", "10.00%", 1000.00, "10% ของ ฿10,000"),
            ("UAT ขาขวา", "direct", "10.00%", 1000.00, "10% ของ ฿10,000"),
            ("UAT ผู้สนับสนุน", "binary_match", "10.00%", 1000.00,
             "หลังรันรอบจับคู่เท่านั้น: จับคู่ได้ min(฿10,000, ฿10,000) = ฿10,000 → 10%"),
        ],
        "watch": [
            "ก่อนรันรอบจับคู่ ผู้สนับสนุนต้องได้ ฿0 — อันนี้ถูกต้อง ไม่ใช่บั๊ก Binary ไม่จ่ายต่อการขาย",
            "สั่งรันรอบ: php artisan commissions:run-binary-cycles",
            "ระวัง: คำสั่งนั้นประมวลผลทุกบริษัทที่ตั้ง Binary ไว้ ไม่ใช่เฉพาะ UAT — "
            "ตัวคำสั่ง seed จะบอกท้ายผลลัพธ์ว่ามีบริษัทอื่นติดร่างแหไหม",
            "ตัวแทนที่ไม่ได้ตั้งขา (ซ้าย/ขวา) จะไม่ส่งยอดขึ้นไปเลย และไม่มีข้อความเตือนใด ๆ",
        ],
    },
    {
        "tab": "3 Matrix",
        "title": "Matrix — ผังกว้างจำกัด และคิดจาก PV",
        "slug": "uat-plan-matrix",
        "basis": "PV ฿7,000.00  (ไม่ใช่ราคาขาย ฿10,000.00)",
        "rule": "จ่ายขึ้นไปตามผัง matrix ของตัวเอง (คนละต้นกับสายหัวหน้า) ตามอัตราของแต่ละชั้น "
                "บริษัทนี้ตั้งใจให้คิดจาก PV เพื่อทดสอบฐานการคำนวณ",
        "setup": [
            (S_CHIP, "แผนที่เลือกอยู่", "Matrix", "ชิปของแผนอื่นต้องกดไม่ได้และมีรูปกุญแจ"),
            (S_BASIS, "ฐานการคำนวณ", "PV  ← บริษัทเดียวในชุดนี้ที่ใช้ PV",
             "ปุ่ม “PV” ต้องมีเครื่องหมายถูก และขั้นที่ 3 ต้องมีแถบฟ้าเตือนว่าคิดจาก PV ไม่ใช่ราคาขาย"),
            (S_PV, "UAT Package", "7,000.00",
             "บรรทัดบนต้องบอก “ราคาขาย ฿10,000.00” — สองตัวเลขนี้ต้องไม่เท่ากัน ไม่งั้นทดสอบฐาน PV ไม่ได้"),
            (S_PV, "ป้าย “ยังไม่ได้กำหนด n รายการ”", "ต้องไม่ขึ้น",
             "ขึ้นเมื่อมีสินค้าที่ยังไม่ได้ใส่ PV — ตารางนี้จะโผล่เฉพาะบริษัทที่ใช้ฐาน PV"),
            ("ขั้นที่ 2 · ฟอร์ม “ค่าตั้งระดับบริษัทของแผน Matrix”", "ความกว้าง (Width)", "3", ""),
            ("ขั้นที่ 2 · ฟอร์ม “ค่าตั้งระดับบริษัทของแผน Matrix”", "ความลึก (Depth)", "2", ""),
            ("ขั้นที่ 2 · ฟอร์ม “ค่าตั้งระดับบริษัทของแผน Matrix”",
             "กฎ Spillover", "Breadth-first (กว้างก่อน)", "ตอนนี้มีตัวเลือกเดียว"),
            ("ขั้นที่ 2 · กล่อง “ตัวอย่างโครงสร้าง”", "หัวข้อกล่อง", "ตัวอย่างโครงสร้าง 3 x 2",
             "ต้องวาด 2 แถว แถวละ 3 ช่อง ป้ายกำกับ 1.1–1.3 และ 2.1–2.3"),
            ("ขั้นที่ 2 · รายการ “อัตราค่าแนะนำตาม Level”", "Level 1", "Level 1 — 5.00%", ""),
            ("ขั้นที่ 2 · รายการ “อัตราค่าแนะนำตาม Level”", "Level 2", "Level 2 — 3.00%",
             "ต้องมีแค่ 2 รายการ ให้เท่ากับความลึกที่ตั้งไว้"),
            (S_STEP3, "ค่าเริ่มต้นทั้งบริษัท", "10.00%",
             "10% ของ PV ฿7,000 = ฿700 ไม่ใช่ ฿1,000 — ขั้นที่ 3 ต้องมีแถบฟ้าบอกเรื่องนี้ไว้"),
            *STEP4_HEAD,
            (S_STEP4, "4.3 อัตราหัวหน้าทีม · ค่าเริ่มต้นทั้งบริษัท", "ไม่มีรายการ",
             "แผนนี้จ่ายชั้นบนด้วยอัตราตาม Level ข้างบน ไม่ได้ใช้อัตราหัวหน้าทีม"),
            *STEP4_TAIL,
            (S_OFF, "ผังวางตำแหน่ง (matrix placements)", "ชั้นบนสุด → ชั้นกลาง → ผู้ขาย",
             "ไม่มีหน้าจอตั้งค่า — คำสั่ง seed วางให้ตรงกับสายหัวหน้า ในระบบจริงสองอย่างนี้ต่างกันได้"),
        ],
        "rows": [
            ("UAT ผู้ขาย", "direct", "10.00%", 700.00, "10% ของ PV ฿7,000 ไม่ใช่ของราคา ฿10,000"),
            ("UAT ชั้นกลาง", "matrix_override", "5.00%", 350.00, "5% ของ PV ฿7,000"),
            ("UAT ชั้นบนสุด", "matrix_override", "3.00%", 210.00, "3% ของ PV ฿7,000"),
        ],
        "watch": [
            "ตัวเลขทั้งหมดต้องเป็น 70% ของบริษัทที่คิดจากราคา — ถ้าได้ ฿1,000 แทน ฿700 แปลว่าฐาน PV ไม่ถูกใช้",
            "Matrix เดินตามผังของตัวเอง ไม่ใช่สายหัวหน้า — ตัวแทนที่มีหัวหน้าแต่ไม่ถูกวางในผัง จะไม่ได้อะไรเลยโดยไม่มีคำเตือน",
            "ลองลดราคาสินค้าแล้วขายใหม่: ค่าคอมต้องไม่ลดตาม เพราะคิดจาก PV",
        ],
    },
    {
        "tab": "4 Stairstep",
        "title": "Stairstep — หัวหน้าได้เฉพาะส่วนต่างของขั้น",
        "slug": "uat-plan-stairstep-breakaway",
        "basis": "ราคาขาย ฿10,000.00",
        "rule": "หัวหน้าแต่ละคนได้เฉพาะส่วนที่อัตราขั้นของตัวเองสูงกว่าขั้นของลูกทีม "
                "และหยุดทันทีที่เจอคนที่ถึงขั้นตัดสาย",
        "setup": [
            (S_CHIP, "แผนที่เลือกอยู่", "อันดับ (Stairstep)",
             "ชื่อบนชิปเขียนแบบนี้ ไม่ใช่ “Stairstep” เฉย ๆ"),
            (S_BASIS, "ฐานการคำนวณ", "ราคาขาย", "ทั้งสองปุ่มกดไม่ได้"),
            ("ขั้นที่ 2 · ฟอร์ม “ค่าตั้งระดับบริษัทของแผนอันดับ (Stairstep)”",
             "หน้าต่างคำนวณยอดย้อนหลัง (วัน)", "90",
             "ช่องนี้เคยว่างเพราะคำสั่ง seed ไม่ได้เขียนแถวค่าตั้งให้ — แก้แล้ว 2026-09-22"),
            ("ขั้นที่ 2 · ฟอร์ม “ค่าตั้งระดับบริษัทของแผนอันดับ (Stairstep)”",
             "ความถี่คำนวณอันดับใหม่", "รายเดือน", "อีกสองตัวเลือกคือ รายวัน / รายสัปดาห์"),
            ("ขั้นที่ 2 · ฟอร์ม “ค่าตั้งระดับบริษัทของแผนอันดับ (Stairstep)”",
             "นับยอดของใคร", "เฉพาะยอดที่ขายเอง (ค่าเริ่มต้น — เท่าเดิม)",
             "ตั้งเป็นค่าเริ่มต้นของระบบไว้ · ถ้าเลือก “ยอดทั้งทีม” ต้องมีข้อความเหลืองเตือนขึ้นทันที"),
            ("ขั้นที่ 2 · รายการ “บันไดอันดับ”", "อันดับที่ 1",
             "1. UAT ขั้นเริ่มต้น · ยอดขั้นต่ำ 0.00 · อัตรา 5.00%",
             "ชื่อขั้นขึ้นต้นด้วย “UAT ” ทุกอัน — ถ้าไม่มีคำนี้ แปลว่าเป็นข้อมูลของบริษัทอื่น"),
            ("ขั้นที่ 2 · รายการ “บันไดอันดับ”", "อันดับที่ 2",
             "2. UAT ขั้นผู้นำ · ยอดขั้นต่ำ 50,000.00 · อัตรา 10.00%", ""),
            ("ขั้นที่ 2 · รายการ “บันไดอันดับ”", "อันดับที่ 3",
             "3. UAT ขั้นผู้จัดการ (Breakaway) · ยอดขั้นต่ำ 200,000.00 · อัตรา 15.00%",
             "ป้าย “(Breakaway)” สีเหลืองต้องขึ้นเฉพาะอันดับนี้"),
            (S_STEP3, "ค่าเริ่มต้นทั้งบริษัท", "5.00%",
             "ตั้งให้เท่ากับอัตราของ “UAT ขั้นเริ่มต้น” โดยตั้งใจ — อ่านหมายเหตุในขั้นที่ 3 ของชีตนี้"),
            *STEP4_HEAD,
            (S_STEP4, "4.3 อัตราหัวหน้าทีม · ค่าเริ่มต้นทั้งบริษัท", "ไม่มีรายการ",
             "แผนนี้จ่ายชั้นบนด้วยส่วนต่างของอันดับ ไม่ได้ใช้อัตราหัวหน้าทีม"),
            *STEP4_TAIL,
            (S_OFF, "อันดับปัจจุบันของแต่ละคน",
             "ผู้ขาย = ขั้นเริ่มต้น · ผู้นำ = ขั้นผู้นำ · ผู้จัดการ = ขั้นผู้จัดการ",
             "คำสั่ง seed วางอันดับให้โดยตรง ไม่ได้รอรอบคำนวณ — หน้าค่าแนะนำไม่มีช่องให้ดูหรือแก้อันดับรายคน"),
            (S_OFF, "สายงาน (ใครเป็นหัวหน้าของใคร)", "ผู้ขาย → ผู้นำ → ผู้จัดการ (ตัดสาย)",
             "ดูที่เมนูจัดการผู้ใช้ระบบ ช่อง “หัวหน้า”"),
        ],
        "rows": [
            ("UAT ผู้ขาย", "direct", "5.00%", 500.00, "5% ของ ฿10,000"),
            ("UAT ผู้นำ", "stairstep_override", "5.00%", 500.00, "ส่วนต่าง 10% − 5% = 5%"),
            ("UAT ผู้จัดการ (ตัดสาย)", "stairstep_override", "5.00%", 500.00, "ส่วนต่าง 15% − 10% = 5%"),
        ],
        "watch": [
            "สามแถวได้เท่ากันโดยบังเอิญจากค่าที่ตั้งไว้ — ตรวจที่ชื่อคน อย่าตรวจแค่ยอด",
            "คำถามออกแบบที่ยังค้าง: อัตราของผู้ขายมาจาก commission_rules ไม่ได้มาจากขั้นของตัวเอง "
            "ชุดทดสอบนี้จงใจตั้งให้ตรงกันไว้ ถ้าตั้งไม่ตรงกันจะมี 'อัตราผู้ขาย' สองค่าบนหน้าจอเดียว",
            "ลองเลื่อนผู้ขายขึ้นเป็นขั้นตัดสาย: หัวหน้าทุกคนเหนือขึ้นไปต้องได้ ฿0 ทันที",
            "ฟอร์มสามช่องบนสุด (หน้าต่างย้อนหลัง / ความถี่ / นับยอดของใคร) เคยว่างเปล่าจนถึง 2026-09-22 "
            "เพราะคำสั่ง seed ไม่ได้เขียนแถวค่าตั้งให้ — ถ้ายังว่างอยู่ แปลว่ารันคำสั่ง seed เวอร์ชันเก่า",
            "ค่าในสามช่องนั้นใช้โดยรอบคำนวณอันดับอัตโนมัติ ซึ่งชุดทดสอบนี้ไม่ได้รัน "
            "(อันดับของทุกคนถูกวางไว้โดยตรง) — จึงเปลี่ยนค่าแล้วยอดในขั้นที่ 2 ต้องไม่ขยับ",
        ],
    },
    {
        "tab": "5 Generation",
        "title": "Generation — ข้ามคนที่ยังไม่ถึงขั้นตัดสาย",
        "slug": "uat-plan-generation",
        "basis": "ราคาขาย ฿10,000.00",
        "rule": "เดินขึ้นจากผู้ขาย นับเป็นรุ่นเฉพาะเมื่อเจอคนที่ถึงขั้นตัดสาย "
                "คนที่ยังไม่ถึงขั้นถูกข้ามไปเฉย ๆ ไม่ได้เงินและไม่นับเป็นรุ่น",
        "setup": [
            (S_CHIP, "แผนที่เลือกอยู่", "Generation", "ชิปของแผนอื่นต้องกดไม่ได้และมีรูปกุญแจ"),
            (S_BASIS, "ฐานการคำนวณ", "ราคาขาย", "ทั้งสองปุ่มกดไม่ได้"),
            ("ขั้นที่ 2 · ฟอร์ม “ค่าตั้งระดับบริษัทของแผน Generation”",
             "ความลึกสูงสุด (จำนวน Generation)", "2",
             "ฟอร์มนี้มีช่องเดียว — ถ้าเห็นมากกว่านี้ แสดงว่าดูผิดแผน"),
            ("ขั้นที่ 2 · รายการ “อัตราค่าแนะนำตาม Generation”",
             "Generation 1", "Generation 1 — 5.00%", ""),
            ("ขั้นที่ 2 · รายการ “อัตราค่าแนะนำตาม Generation”",
             "Generation 2", "Generation 2 — 3.00%", "ต้องมีแค่ 2 รายการ ให้เท่ากับความลึกที่ตั้งไว้"),
            (S_STEP3, "ค่าเริ่มต้นทั้งบริษัท", "10.00%", ""),
            *STEP4_HEAD,
            (S_STEP4, "4.3 อัตราหัวหน้าทีม · ค่าเริ่มต้นทั้งบริษัท", "ไม่มีรายการ",
             "แผนนี้จ่ายชั้นบนด้วยอัตราตามรุ่นข้างบน ไม่ได้ใช้อัตราหัวหน้าทีม"),
            *STEP4_TAIL,
            (S_OFF, "บันไดอันดับ (ใช้ตัดสินว่าใคร “ตัดสาย”)",
             "UAT ขั้นเริ่มต้น 5% · UAT ขั้นผู้นำ 10% · UAT ขั้นผู้จัดการ 15% (Breakaway)",
             "หน้าตั้งค่าของบริษัทที่ใช้แผน Generation ไม่แสดงฟอร์มบันไดอันดับเลย ทั้งที่แผนนี้ใช้มันคำนวณ "
             "— ข้อมูลถูกสร้างไว้ให้แล้ว แต่ตรวจบนหน้าจอนี้ไม่ได้ ถือเป็นข้อสังเกตที่ควรรายงาน"),
            (S_OFF, "อันดับปัจจุบันของแต่ละคน",
             "ข และ ง = ขั้นผู้จัดการ (ตัดสาย) · ก, ค, ผู้ขาย = ขั้นเริ่มต้น",
             "นี่คือสิ่งที่ทำให้ ก และ ค ถูกข้าม — ดูขั้นที่ 2 ของชีตนี้"),
            (S_OFF, "สายงาน (ใครเป็นหัวหน้าของใคร)",
             "ผู้ขาย → ก → ข (ตัดสาย) → ค → ง (ตัดสาย)",
             "ดูที่เมนูจัดการผู้ใช้ระบบ ช่อง “หัวหน้า”"),
        ],
        "rows": [
            ("UAT ผู้ขาย", "direct", "10.00%", 1000.00, "10% ของ ฿10,000"),
            ("UAT หัวหน้า ก", "—", "—", 0.00, "ยังไม่ถึงขั้นตัดสาย → ถูกข้าม ไม่มีแถวในบัญชี"),
            ("UAT หัวหน้า ข (ตัดสาย)", "generation_override", "5.00%", 500.00, "รุ่นที่ 1"),
            ("UAT หัวหน้า ค", "—", "—", 0.00, "ยังไม่ถึงขั้นตัดสาย → ถูกข้าม ไม่มีแถวในบัญชี"),
            ("UAT หัวหน้า ง (ตัดสาย)", "generation_override", "3.00%", 300.00, "รุ่นที่ 2"),
        ],
        "watch": [
            "หัวหน้า ก อยู่เหนือผู้ขายโดยตรงแต่ได้ ฿0 — นี่คือพฤติกรรมที่ถูกต้องของแผนนี้ "
            "และเป็นเรื่องที่คนใช้งานจริงจะร้องเรียนมากที่สุด",
            "ต้องมีแค่ 3 แถวในบัญชี ไม่ใช่ 5 — คนที่ถูกข้ามต้องไม่มีแถว ฿0",
            "ลองเลื่อน ก ขึ้นเป็นขั้นตัดสาย: ก ต้องกลายเป็นรุ่นที่ 1 และ ง ต้องหลุดออกไป (เพราะจำกัด 2 รุ่น)",
            "หน้าตั้งค่าของแผนนี้ไม่แสดงบันไดอันดับเลย ทั้งที่ “ตัดสาย” คือหัวใจของการคำนวณ — "
            "ข้อมูลอันดับถูกสร้างไว้ให้แล้ว แต่ตรวจจากหน้าจอนี้ไม่ได้ ถือเป็นข้อสังเกตที่ควรรายงาน",
        ],
    },
    {
        "tab": "6 Affiliate",
        "title": "พันธมิตร (Affiliate) — จ่ายขึ้นไปชั้นเดียว",
        "slug": "uat-plan-affiliate",
        "basis": "ราคาขาย ฿10,000.00",
        "rule": "ผู้แนะนำโดยตรงได้ส่วนแบ่งชั้นเดียว ไม่เดินขึ้นต่อ",
        "setup": [
            (S_CHIP, "แผนที่เลือกอยู่", "พันธมิตร (Affiliate)",
             "ชื่อบนชิปเขียนแบบนี้ ไม่ใช่ “Affiliate” เฉย ๆ"),
            (S_BASIS, "ฐานการคำนวณ", "ราคาขาย", "ทั้งสองปุ่มกดไม่ได้"),
            ("ขั้นที่ 2 · ฟอร์ม “ค่าตั้งระดับบริษัทของแผนพันธมิตร (Affiliate)”",
             "หน้าต่างนับเครดิต (วัน)", "30",
             "ช่องนี้เคยว่างเพราะคำสั่ง seed ไม่ได้เขียนแถวค่าตั้งให้ — แก้แล้ว 2026-09-22"),
            ("ขั้นที่ 2 · ฟอร์ม “ค่าตั้งระดับบริษัทของแผนพันธมิตร (Affiliate)”",
             "แยกอัตราลูกค้าใหม่/ลูกค้าเก่า", "ไม่ติ๊ก",
             "ป้ายบนหน้าจอบอกเองว่า “ยังไม่รองรับการคำนวณจริง” จึงตั้งปิดไว้โดยตั้งใจ"),
            (S_STEP3, "ค่าเริ่มต้นทั้งบริษัท", "10.00%", "อัตราของผู้ขาย ไม่ใช่ของผู้แนะนำ"),
            (S_STEP4, "4.1 เงินของหัวหน้าทีมมาจากไหน", "บริษัทจ่ายเพิ่ม",
             "ผู้ขายต้องได้เต็ม ฿1,000 ไม่ถูกหัก — ถ้าโหมดเป็นแบบหัก ตัวเลขในขั้นที่ 2 จะเปลี่ยนทั้งตาราง"),
            (S_STEP4, "4.2 จ่ายขึ้นไปกี่ชั้น", "กล่องนี้ต้องไม่ขึ้นเลย", "มีเฉพาะบริษัทที่ใช้แผน Unilevel"),
            (S_STEP4, "4.3 อัตราหัวหน้าทีม · ค่าเริ่มต้นทั้งบริษัท",
             "3.00% · ป้าย “ตามค่าเริ่มต้นบริษัท (บริษัทจ่ายเพิ่ม)”",
             "นี่คืออัตราของผู้แนะนำ — แผนนี้จ่ายผ่านอัตราหัวหน้าทีม ไม่ได้จ่ายจากฟอร์มในขั้นที่ 2"),
            (S_STEP4, "4.7 ยอดขั้นต่ำในการเบิก", "เว้นว่าง", "เว้นว่าง = ไม่มีขั้นต่ำ"),
            (S_STEP4, "4.8 ภาษีหัก ณ ที่จ่าย", "3.00%  ← บริษัทเดียวในชุดนี้ที่หัก",
             "มีผลตอนโอนเงินเท่านั้น ไม่เปลี่ยนยอดที่ลงบัญชี — ดูชีต 8"),
            (S_OFF, "สายงาน (ใครเป็นหัวหน้าของใคร)", "ผู้ขาย → ผู้แนะนำ",
             "ดูที่เมนูจัดการผู้ใช้ระบบ ช่อง “หัวหน้า”"),
        ],
        "rows": [
            ("UAT ผู้ขาย", "direct", "10.00%", 1000.00, "10% ของ ฿10,000 — ไม่ถูกหัก"),
            ("UAT ผู้แนะนำ", "override", "3.00%", 300.00, "3% ของ ฿10,000 บริษัทจ่ายเพิ่ม"),
        ],
        "watch": [
            "จบแค่ชั้นเดียว — ถ้าผู้แนะนำมีหัวหน้าอีกชั้น คนนั้นต้องไม่ได้อะไร",
            "อัตรา 3% ของผู้แนะนำอยู่ที่ขั้นที่ 4 ข้อ 4.3 ไม่ได้อยู่ในฟอร์มของแผนในขั้นที่ 2 — "
            "ฟอร์มในขั้นที่ 2 คุมแค่หน้าต่างนับเครดิตของลิงก์พันธมิตร",
            "ลองสลับเป็นโหมด deductive: ผู้ขายต้องเหลือ ฿700 และยอดจ่ายออกรวมต้องเท่าเดิมที่ ฿1,000",
            "บริษัทนี้หักภาษี ณ ที่จ่าย 3% — ยอดในตารางนี้คือยอดก่อนหัก ดูยอดโอนจริงในชีต 8",
        ],
    },
]


def cover(wb, ranges):
    ws = wb.create_sheet("เริ่มที่นี่")
    ws.sheet_view.showGridLines = False
    ws.column_dimensions["A"].width = 4
    ws.column_dimensions["B"].width = 34
    ws.column_dimensions["C"].width = 96

    h1(ws, "B2", "UAT — ตรวจการตั้งค่าและการแบ่งค่าคอมทั้ง 6 แผน")
    note(ws, "B3", "Live to 100 Club · เอกสารนี้คู่กับคำสั่ง uat:seed-commission-plans")

    rows = [
        ("สิ่งที่ชุดนี้ทำ",
         "สร้างบริษัททดสอบ 6 แห่ง แห่งละหนึ่งแผนค่าแนะนำ พร้อมสินค้า ตัวแทน สายงาน อัตรา "
         "และ 'การขายจริง' หนึ่งรายการต่อบริษัท (ใบสั่งซื้อ → สลิป → ยืนยันชำระเงิน) "
         "เพื่อให้ตรวจได้ว่าระบบ 'จ่ายจริง' ตรงกับที่แผนบอกไว้หรือไม่"),
        ("ทำไมต้องขายจริง",
         "หน้าตั้งค่าบอกได้แค่ว่า 'ตั้งอะไรไว้' ไม่ได้บอกว่า 'จ่ายเท่าไร' — และช่องว่างระหว่างสองอย่างนี้ "
         "คือที่ที่บั๊กซ่อนอยู่ เคสจริงที่เจอมาแล้ว: ออเดอร์ชำระแล้ว 14 ใบ แต่ไม่มีค่าคอมลงบัญชีเลยสักบาท"),
        ("วิธีรัน",
         "php artisan uat:seed-commission-plans        (ถามยืนยันก่อน 1 ครั้ง)\n"
         "php artisan uat:purge-commission-plans       (ลบทิ้งทั้งหมดเมื่อทดสอบเสร็จ)"),
        ("ปลอดภัยกับ production ไหม",
         "ใช่ — ทุกแถวที่สร้างขึ้นผูกกับบริษัทที่ slug ขึ้นต้นด้วย uat-plan- เท่านั้น "
         "คำสั่งไม่อ่านและไม่แก้บริษัทอื่นเลย และคำสั่งลบจะปฏิเสธถ้าเจอบริษัทที่ไม่ใช่ของมัน"),
        ("ล็อกอินเป็นตัวแทน UAT ได้ไหม",
         "ไม่ได้ และตั้งใจให้ไม่ได้ — บัญชีเหล่านี้ตั้งรหัสผ่านแบบสุ่มและใช้อีเมล .invalid ที่ส่งเมลไม่ถึง "
         "ตรวจทุกอย่างผ่านหน้า Admin ด้วยบัญชี Super Admin ของคุณเอง "
         "(การสร้างบัญชีรหัสผ่านที่เดาได้ 6 บัญชีบนเซิร์ฟเวอร์จริง คือการเปิดทางเข้าเพิ่ม 6 ทางโดยไม่จำเป็น)"),
        ("ตัวเลขในเอกสารนี้มาจากไหน",
         "คำนวณด้วยมือจากกติกาของแต่ละแผน ไม่ได้ copy มาจากผลลัพธ์ที่ระบบพ่นออกมา "
         "ถ้าระบบให้ตัวเลขไม่ตรงกับช่อง 'ควรได้' แปลว่ามีอย่างใดอย่างหนึ่งผิด — นั่นคือสิ่งที่ QA ต้องรายงาน"),
        ("อ่านชีตของแต่ละแผนยังไง",
         "ขั้นที่ 1 = ไล่ตรวจหน้าจอทีละช่อง จัดกลุ่มตามส่วนของหน้าจอ (แถบสีเทาคือชื่อส่วน) และบอกด้วยว่า "
         "ช่องนั้นอยู่ขั้นที่ 2, 3 หรือ 4 ของหน้า 'ค่าแนะนำ' · ชื่อช่องเขียนตรงตามที่หน้าจอเขียน "
         "· กลุ่มสุดท้าย 'ไม่ได้อยู่บนหน้านี้' คือสิ่งที่ต้องไปดูที่เมนูอื่น เช่น สายงาน\n"
         "ขั้นที่ 2 = ตรวจยอดค่าแนะนำที่ลงบัญชีจริง · ขั้นที่ 3 = จุดที่มักพลาดของแผนนั้น"),
        ("ค่าที่ใช้ทดสอบ",
         "ราคาสินค้า ฿10,000.00 · PV ฿7,000.00 · อัตราผู้ขาย 10% · หัวหน้า 5/3/1% "
         "— เลือกให้คิดเลขในใจได้ ไม่ใช่ข้อเสนอว่าบริษัทจริงควรจ่ายเท่าไร (BR-7)"),
        ("ตั้งจ่ายเงินได้เลย",
         "ตัวแทน UAT ทุกคนถูกกรอกบัญชีธนาคารและเอกสารยืนยันตัวตนไว้ให้แล้ว จึงขึ้นในคิว "
         "'ตั้งจ่ายได้เลย' ได้ทันที — เลขบัญชีและเลขเอกสารขึ้นต้นด้วย UAT ทั้งหมด "
         "และเป็นหนังสือเดินทางไม่ใช่เลขบัตรประชาชน จึงไม่มีทางชนกับข้อมูลของคนจริง "
         "(ดูชีต 8)"),
        ("ยังไม่ครอบคลุม",
         "การคืนเงินและการกลับรายการ · ค่าคอมต่ออายุ · การแบ่งค่าคอมกับผู้ขายร่วม — "
         "แต่ละเรื่องมีทางเดินของตัวเองและควรมีชุดทดสอบแยก"),
    ]

    r = 5
    for label, text in rows:
        ws.cell(row=r, column=2, value=label).font = Font(name=FONT, size=10, bold=True, color=NAVY)
        ws.cell(row=r, column=2).alignment = Alignment(vertical="top")
        c = ws.cell(row=r, column=3, value=text)
        c.font = Font(name=FONT, size=10)
        c.alignment = Alignment(wrap_text=True, vertical="top")
        ws.row_dimensions[r].height = 15 * (1 + text.count("\n") + len(text) // 95)
        r += 2

    ws.cell(row=r + 1, column=2, value="สรุปผลรวมทุกแผน").font = Font(name=FONT, size=11, bold=True, color=NAVY)
    header_row(ws, r + 2, ["", "แผน", "ยอดจ่ายออกรวมที่ควรได้ (บาท)"], [4, 34, 96])
    ws.cell(row=r + 2, column=1).fill = PatternFill("solid", fgColor=NAVY)

    for i, plan in enumerate(PLANS):
        row = r + 3 + i
        ws.cell(row=row, column=2, value=plan["title"].split(" — ")[0]).font = Font(name=FONT, size=10)
        ws.cell(row=row, column=2).border = box
        # A live cross-sheet sum against the rows the tab actually used, so
        # the cover cannot drift from the tabs if a plan gains a payee.
        first, last = ranges[plan["tab"]]
        ref = "'{}'!D{}:D{}".format(plan["tab"], first, last)
        c = ws.cell(row=row, column=3, value="=SUM({})".format(ref))
        c.font = Font(name=FONT, size=10)
        c.number_format = '#,##0.00'
        c.alignment = Alignment(horizontal="right")
        c.border = box

    note(ws, "C{}".format(r + 3 + len(PLANS) + 1),
         "ยอดของ Binary รวมส่วนที่ต้องรันรอบจับคู่ก่อนถึงจะเกิดขึ้น · ยอดของ Matrix คิดจาก PV ไม่ใช่ราคา")


def plan_sheet(wb, plan):
    ws = wb.create_sheet(plan["tab"])
    ws.sheet_view.showGridLines = False
    for col, width in zip("ABCDEF", [26, 20, 12, 16, 46, 14]):
        ws.column_dimensions[col].width = width

    h1(ws, "A1", plan["title"])
    note(ws, "A2", "บริษัท: {} · ฐานการคำนวณ: {}".format(plan["slug"], plan["basis"]))
    note(ws, "A3", "กติกา: " + plan["rule"])
    ws.row_dimensions[3].height = 30

    # ── what should be on the settings screen ──
    #
    # Grouped by the section of the screen the control sits in, because the
    # question a tester is actually answering is "where do I look", and a flat
    # list of thirteen names spread over three steps does not answer it. The
    # value column spans B:D so a full sentence fits without squeezing the
    # payout table below, which needs those three columns narrow.
    ws["A5"] = "ขั้นที่ 1 — เปิดหน้า “ค่าแนะนำ” ของบริษัทนี้ แล้วไล่ตรวจทีละช่อง"
    ws["A5"].font = Font(name=FONT, size=11, bold=True, color=NAVY)
    note(ws, "A6", "แถบสีคือหัวข้อของส่วนบนหน้าจอ · ชื่อในคอลัมน์ “ช่องบนหน้าจอ” เขียนตรงตามที่หน้าจอเขียนไว้")

    header_row(ws, 7, ["ช่องบนหน้าจอ", "ต้องเป็น", "", "", "หมายเหตุ", "ตรงไหม"],
               [26, 20, 12, 16, 46, 14])
    ws.merge_cells(start_row=7, start_column=2, end_row=7, end_column=4)

    r = 8
    section = None
    for where, label, value, why in plan["setup"]:
        if where != section:
            section = where
            c = ws.cell(row=r, column=1, value=where)
            c.font = Font(name=FONT, size=10, bold=True, color=NAVY)
            c.fill = PatternFill("solid", fgColor=GREY)
            c.alignment = Alignment(vertical="center")
            for col in range(1, 7):
                ws.cell(row=r, column=col).fill = PatternFill("solid", fgColor=GREY)
                ws.cell(row=r, column=col).border = box
            ws.merge_cells(start_row=r, start_column=1, end_row=r, end_column=6)
            r += 1

        body_row(ws, r, [label, value, "", "", why, ""])
        ws.merge_cells(start_row=r, start_column=2, end_row=r, end_column=4)
        ws.cell(row=r, column=6).fill = PatternFill("solid", fgColor=AMBER)
        # One line per ~48 characters in the widest of the two text cells.
        ws.row_dimensions[r].height = 14 * max(1, len(value) // 48 + 1, len(why) // 52 + 1)
        r += 1

    # ── what should be paid ──
    #
    # The row this table starts on varies with how many settings the plan
    # lists, so it is computed rather than pinned — and handed back to the
    # cover sheet, whose cross-sheet SUM would otherwise be a number that
    # happens to be right today.
    r += 1
    ws.cell(row=r, column=1, value="ขั้นที่ 2 — ตรวจว่าค่าคอมที่ลงบัญชีตรงกับนี้").font = \
        Font(name=FONT, size=11, bold=True, color=NAVY)

    r += 1
    header_row(ws, r, ["ใคร", "ได้จาก (earned_via)", "อัตรา", "ควรได้ (บาท)", "ที่มาของตัวเลข", "ผ่าน / ไม่ผ่าน"],
               [26, 20, 12, 16, 46, 14])

    r += 1
    first_payout_row = r
    for who, via, rate, amount, why in plan["rows"]:
        zero = amount == 0
        body_row(ws, r, [who, via, rate, amount, why, ""], money_cols=(4,),
                 fill=GREY if zero else None)
        ws.cell(row=r, column=6).fill = PatternFill("solid", fgColor=AMBER)
        if zero:
            ws.cell(row=r, column=1).font = Font(name=FONT, size=10, italic=True, color=SLATE)
        r += 1

    total = ws.cell(row=r, column=4, value="=SUM(D{}:D{})".format(first_payout_row, r - 1))
    total.font = Font(name=FONT, size=10, bold=True)
    total.number_format = '#,##0.00'
    total.fill = PatternFill("solid", fgColor=GREEN)
    total.border = box
    label = ws.cell(row=r, column=3, value="รวมจ่ายออก")
    label.font = Font(name=FONT, size=10, bold=True)
    label.alignment = Alignment(horizontal="right")
    label.border = box

    # ── what to watch for ──
    r += 2
    ws.cell(row=r, column=1, value="ขั้นที่ 3 — จุดที่มักพลาด").font = Font(name=FONT, size=11, bold=True, color=NAVY)
    r += 1
    for w in plan["watch"]:
        c = ws.cell(row=r, column=1, value="· " + w)
        c.font = Font(name=FONT, size=10, color=SLATE)
        c.alignment = Alignment(wrap_text=True, vertical="top")
        ws.merge_cells(start_row=r, start_column=1, end_row=r, end_column=6)
        ws.row_dimensions[r].height = 15 * (1 + len(w) // 110)
        r += 1

    r += 1
    ws.cell(row=r, column=1, value="บันทึกของผู้ทดสอบ").font = Font(name=FONT, size=11, bold=True, color=NAVY)
    ws.merge_cells(start_row=r + 1, start_column=1, end_row=r + 4, end_column=6)
    cell = ws.cell(row=r + 1, column=1)
    cell.fill = PatternFill("solid", fgColor=AMBER)
    cell.border = box
    cell.alignment = Alignment(wrap_text=True, vertical="top")

    return first_payout_row, first_payout_row + len(plan["rows"]) - 1


def lock_sheet(wb):
    """The one behaviour that is not about a single plan."""
    ws = wb.create_sheet("7 ล็อกแผน")
    ws.sheet_view.showGridLines = False
    for col, width in zip("ABCD", [8, 62, 44, 16]):
        ws.column_dimensions[col].width = width

    h1(ws, "A1", "ล็อกแผนเมื่อบริษัทขายไปแล้ว")
    note(ws, "A2",
         "ทั้ง 6 บริษัทมีออเดอร์ที่ชำระแล้วและมีค่าคอมลงบัญชี จึงควรถูกล็อกทุกแห่ง "
         "— ใช้ชุดนี้ตรวจว่ากฎล็อกทำงานเหมือนกันทุกแผน")
    ws.row_dimensions[2].height = 30

    header_row(ws, 4, ["#", "สิ่งที่ต้องเห็น", "ตรวจที่ไหน", "ผ่าน / ไม่ผ่าน"], [8, 62, 44, 16])

    checks = [
        ("แถบเทามีไอคอนกุญแจ บอกจำนวนออเดอร์และจำนวนรายการค่าแนะนำ พร้อมวันที่รายการแรก",
         "ขั้นที่ 2 ใต้ชิปแผน"),
        ("ปุ่ม 'ใช้แผนนี้' ต้องไม่มี", "ขั้นที่ 2"),
        ("ชิปของแผนอื่นกดไม่ได้ และมีไอคอนกุญแจบนชิป", "ขั้นที่ 2 แถวชิป"),
        ("ชิปของแผนที่บริษัทใช้อยู่ยังกดได้ (เป็นทางกลับ ไม่ใช่ช่องโหว่)", "ขั้นที่ 2 แถวชิป"),
        ("ปุ่มเลือกฐานการคำนวณ (ราคาขาย / PV) กดไม่ได้", "ขั้นที่ 2 การ์ด 'ค่าแนะนำคิดจากอะไร'"),
        ("ยิง PUT /api/v1/commission-settings เปลี่ยนแผนตรง ๆ ต้องได้ 422 พร้อมเหตุผลเป็นตัวเลขเดียวกับที่หน้าจอบอก",
         "เครื่องมือ API / DevTools"),
        ("กดกล่องในผังแล้วเลื่อนไปที่ช่องตั้งค่านั้น พร้อมกรอบเรืองขึ้นชั่วครู่",
         "ขั้นที่ 2 ผังของแผน"),
        ("กดกล่อง 'คนขาย' แล้วข้ามไปขั้นที่ 3 ที่ช่องอัตราเริ่มต้นทั้งบริษัท",
         "ขั้นที่ 2 → ขั้นที่ 3"),
        ("Tab เข้ากล่องในผังได้ และกด Enter / Space แล้วทำงานเหมือนคลิก",
         "ขั้นที่ 2 ผังของแผน (แป้นพิมพ์)"),
    ]

    for i, (what, where) in enumerate(checks, start=1):
        body_row(ws, 4 + i, [i, what, where, ""], fill=GREY if i % 2 else None)
        ws.cell(row=4 + i, column=4).fill = PatternFill("solid", fgColor=AMBER)

    r = 5 + len(checks) + 2
    ws.cell(row=r, column=1, value="หมายเหตุ").font = Font(name=FONT, size=11, bold=True, color=NAVY)
    for line in [
        "บริษัทที่ 'ยังไม่ขาย' ต้องยังเปลี่ยนแผนได้ตามปกติ — ถ้าอยากทดสอบด้านนี้ ให้สร้างบริษัทเปล่าขึ้นมาเอง",
        "ช่องสีเหลืองคือช่องที่ผู้ทดสอบกรอก ช่องอื่นเป็นค่าที่คาดไว้ อย่าแก้",
    ]:
        r += 1
        c = ws.cell(row=r, column=1, value="· " + line)
        c.font = Font(name=FONT, size=10, color=SLATE)
        ws.merge_cells(start_row=r, start_column=1, end_row=r, end_column=4)


def payout_sheet(wb):
    """
    The second half of the money path.

    Added 2026-09-22, after the owner opened the payout screen and found all
    four Unilevel agents under "มีค่าแนะนำ แต่ตั้งจ่ายไม่ได้". The screen was
    right — it refuses to queue money for somebody with no account to send it
    to — and the fixture was simply unfinished. It is finished now, so the
    queue is reachable and worth checking.
    """
    ws = wb.create_sheet("8 จ่ายเงิน")
    ws.sheet_view.showGridLines = False
    for col, width in zip("ABCD", [8, 62, 44, 16]):
        ws.column_dimensions[col].width = width

    h1(ws, "A1", "ตั้งจ่าย → ตรวจสอบ → โอน")
    note(ws, "A2",
         "ตัวแทน UAT ทุกคนมีบัญชีธนาคารและเอกสารยืนยันตัวตนครบแล้ว จึงขึ้นในคิว 'ตั้งจ่ายได้เลย' ได้ "
         "· เลขบัญชีและเลขเอกสารขึ้นต้นด้วย UAT และเป็นหนังสือเดินทางไม่ใช่เลขบัตรประชาชน "
         "จึงไม่มีทางชนกับข้อมูลของคนจริงในระบบค้นหา")
    ws.row_dimensions[2].height = 44

    header_row(ws, 4, ["#", "สิ่งที่ต้องเห็น", "ตรวจที่ไหน", "ผ่าน / ไม่ผ่าน"], [8, 62, 44, 16])

    checks = [
        ("แถบเหลือง 'มีค่าแนะนำ แต่ตั้งจ่ายไม่ได้' ต้องหายไป และทั้ง 4 คนย้ายมาอยู่ขั้นที่ 1",
         "UAT · Unilevel → จ่ายค่าแนะนำ"),
        ("ขั้นที่ 1 ต้องขึ้น 4 คน รวม ฿1,900", "หน้าเดียวกัน"),
        ("ติ๊กทั้ง 4 คนแล้วตั้งจ่าย → ต้องย้ายไปขั้นที่ 2 'รอคุณตรวจสอบ' ครบ",
         "หน้าเดียวกัน"),
        ("อนุมัติ → ย้ายไปขั้นที่ 3 'รอฝ่ายบัญชีโอน' · ยอดยังเท่าเดิม",
         "ขั้นที่ 2"),
        ("บันทึกการโอน → ย้ายไป 'โอนแล้ว' และแถวในบัญชีเปลี่ยนเป็นจ่ายแล้ว",
         "ขั้นที่ 3"),
        ("ไฟล์ CSV สำหรับโอน ดาวน์โหลดได้และมีเลขบัญชี UAT ครบทุกคน",
         "ปุ่มมุมขวาบน"),
        ("บริษัท Affiliate หักภาษี ณ ที่จ่าย 3% — ยอดที่ได้ ฿1,000 ต้องโอนจริง ฿970 "
         "และหน้าจอต้องบอกทั้งสองยอด",
         "UAT · Affiliate → จ่ายค่าแนะนำ"),
        ("อีกห้าบริษัทต้องไม่มีบรรทัดภาษีเลย (ไม่ใช่ขึ้น 0.00)",
         "บริษัทอื่น ๆ"),
        ("ลองลบบัญชีธนาคารของตัวแทนหนึ่งคนออก → เขาต้องหลุดจากขั้นที่ 1 กลับไปอยู่แถบเหลืองทันที",
         "จัดการผู้ใช้ระบบ → กลับมาหน้าจ่าย"),
    ]

    for i, (what, where) in enumerate(checks, start=1):
        body_row(ws, 4 + i, [i, what, where, ""], fill=GREY if i % 2 else None)
        ws.cell(row=4 + i, column=4).fill = PatternFill("solid", fgColor=AMBER)

    r = 5 + len(checks) + 2
    ws.cell(row=r, column=1, value="หมายเหตุ").font = Font(name=FONT, size=11, bold=True, color=NAVY)
    for line in [
        "อัตราภาษี 3% เป็นค่าทดสอบ ไม่ใช่ข้อเสนอว่าควรหักเท่าไร (BR-7) — อัตราจริงยังรอคุณกำหนด",
        "ข้อ 9 เป็นการทดสอบย้อนทาง: ด่านกันโอนเงินต้องปิดกลับได้ ไม่ใช่เปิดแล้วเปิดค้าง",
        "การคืนเงินและการกลับรายการค่าคอมยังไม่อยู่ในชุดนี้ — เป็นทางเดินอีกเส้นและควรมีชุดทดสอบแยก",
    ]:
        r += 1
        c = ws.cell(row=r, column=1, value="· " + line)
        c.font = Font(name=FONT, size=10, color=SLATE)
        ws.merge_cells(start_row=r, start_column=1, end_row=r, end_column=4)


def main():
    wb = Workbook()
    wb.remove(wb.active)

    # Plan tabs first: the cover's cross-sheet sums need the row numbers they
    # actually used, and those depend on how many settings each plan lists.
    ranges = {plan["tab"]: plan_sheet(wb, plan) for plan in PLANS}
    lock_sheet(wb)
    payout_sheet(wb)
    cover(wb, ranges)
    wb.move_sheet("เริ่มที่นี่", offset=-(len(PLANS) + 2))
    out = "/home/claude/be/UAT-commission-plans.xlsx"
    wb.save(out)
    print(out)


if __name__ == "__main__":
    main()
