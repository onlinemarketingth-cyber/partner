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
            ("แผนค่าแนะนำ", "Unilevel"),
            ("ฐานการคำนวณ", "ราคาขาย"),
            ("อัตราผู้ขาย (ทั้งบริษัท)", "10.00%"),
            ("อัตราชั้นที่ 1", "5.00%"),
            ("อัตราชั้นที่ 2", "3.00%"),
            ("อัตราชั้นที่ 3", "1.00%"),
            ("โหมดหักค่าคอมหัวหน้า", "additive (บริษัทจ่ายเพิ่ม)"),
            ("สายงาน", "ผู้ขาย → หัวหน้าชั้น 1 → หัวหน้าชั้น 2 → หัวหน้าชั้น 3"),
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
            ("แผนค่าแนะนำ", "Binary"),
            ("ฐานการคำนวณ", "ราคาขาย"),
            ("อัตราผู้ขาย (ทั้งบริษัท)", "10.00%"),
            ("อัตราจับคู่ (matched)", "10.00%"),
            ("รอบจับคู่", "รายสัปดาห์"),
            ("เพดานต่อรอบ", "ไม่จำกัด"),
            ("ยกยอดที่เหลือไปรอบหน้า", "เปิด"),
            ("สายงาน", "ผู้สนับสนุน ← ขาซ้าย / ขาขวา (คนละ 1 การขาย)"),
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
            ("แผนค่าแนะนำ", "Matrix"),
            ("ฐานการคำนวณ", "PV  ← ต่างจากบริษัทอื่น"),
            ("PV ของสินค้า", "฿7,000.00"),
            ("อัตราผู้ขาย (ทั้งบริษัท)", "10.00%"),
            ("ผังกว้าง / ลึก", "3 ช่อง / 2 ชั้น"),
            ("อัตราชั้นที่ 1 ในผัง", "5.00%"),
            ("อัตราชั้นที่ 2 ในผัง", "3.00%"),
            ("ผัง matrix", "ชั้นบนสุด → ชั้นกลาง → ผู้ขาย"),
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
            ("แผนค่าแนะนำ", "อันดับ (Stairstep)"),
            ("ฐานการคำนวณ", "ราคาขาย"),
            ("อัตราผู้ขาย (ทั้งบริษัท)", "5.00%  ← ตั้งให้เท่ากับขั้นของผู้ขาย"),
            ("ขั้นเริ่มต้น", "5.00% · ยอด ฿0"),
            ("ขั้นผู้นำ", "10.00% · ยอด ฿50,000"),
            ("ขั้นผู้จัดการ", "15.00% · ยอด ฿200,000 · ตัดสาย"),
            ("สายงาน", "ผู้ขาย (เริ่มต้น) → ผู้นำ → ผู้จัดการ (ตัดสาย)"),
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
            ("แผนค่าแนะนำ", "Generation"),
            ("ฐานการคำนวณ", "ราคาขาย"),
            ("อัตราผู้ขาย (ทั้งบริษัท)", "10.00%"),
            ("จำนวนรุ่นสูงสุด", "2"),
            ("อัตรารุ่นที่ 1", "5.00%"),
            ("อัตรารุ่นที่ 2", "3.00%"),
            ("สายงาน", "ผู้ขาย → ก → ข (ตัดสาย) → ค → ง (ตัดสาย)"),
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
        ],
    },
    {
        "tab": "6 Affiliate",
        "title": "พันธมิตร (Affiliate) — จ่ายขึ้นไปชั้นเดียว",
        "slug": "uat-plan-affiliate",
        "basis": "ราคาขาย ฿10,000.00",
        "rule": "ผู้แนะนำโดยตรงได้ส่วนแบ่งชั้นเดียว ไม่เดินขึ้นต่อ",
        "setup": [
            ("แผนค่าแนะนำ", "พันธมิตร (Affiliate)"),
            ("ฐานการคำนวณ", "ราคาขาย"),
            ("อัตราผู้ขาย (ทั้งบริษัท)", "10.00%"),
            ("อัตราผู้แนะนำ", "3.00%"),
            ("โหมด", "additive (บริษัทจ่ายเพิ่ม)"),
            ("สายงาน", "ผู้ขาย → ผู้แนะนำ"),
        ],
        "rows": [
            ("UAT ผู้ขาย", "direct", "10.00%", 1000.00, "10% ของ ฿10,000 — ไม่ถูกหัก"),
            ("UAT ผู้แนะนำ", "override", "3.00%", 300.00, "3% ของ ฿10,000 บริษัทจ่ายเพิ่ม"),
        ],
        "watch": [
            "จบแค่ชั้นเดียว — ถ้าผู้แนะนำมีหัวหน้าอีกชั้น คนนั้นต้องไม่ได้อะไร",
            "ลองสลับเป็นโหมด deductive: ผู้ขายต้องเหลือ ฿700 และยอดจ่ายออกรวมต้องเท่าเดิมที่ ฿1,000",
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
        ("ค่าที่ใช้ทดสอบ",
         "ราคาสินค้า ฿10,000.00 · PV ฿7,000.00 · อัตราผู้ขาย 10% · หัวหน้า 5/3/1% "
         "— เลือกให้คิดเลขในใจได้ ไม่ใช่ข้อเสนอว่าบริษัทจริงควรจ่ายเท่าไร (BR-7)"),
        ("ยังไม่ครอบคลุม",
         "ภาษีหัก ณ ที่จ่าย · การถอนเงิน · การคืนเงินและการกลับรายการ · ค่าคอมต่ออายุ · "
         "การแบ่งค่าคอมกับผู้ขายร่วม — แต่ละเรื่องมีทางเดินของตัวเองและควรมีชุดทดสอบแยก"),
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
    ws["A5"] = "ขั้นที่ 1 — ตรวจว่าหน้าตั้งค่าแสดงค่าเหล่านี้"
    ws["A5"].font = Font(name=FONT, size=11, bold=True, color=NAVY)
    header_row(ws, 6, ["ค่าที่ตั้ง", "ต้องเป็น", "ตรงไหม", "", "", ""], [26, 20, 12, 16, 46, 14])

    r = 7
    for label, value in plan["setup"]:
        body_row(ws, r, [label, value, ""], fill=GREY if r % 2 else None)
        ws.cell(row=r, column=3).fill = PatternFill("solid", fgColor=AMBER)
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


def main():
    wb = Workbook()
    wb.remove(wb.active)

    # Plan tabs first: the cover's cross-sheet sums need the row numbers they
    # actually used, and those depend on how many settings each plan lists.
    ranges = {plan["tab"]: plan_sheet(wb, plan) for plan in PLANS}
    lock_sheet(wb)
    cover(wb, ranges)
    wb.move_sheet("เริ่มที่นี่", offset=-(len(PLANS) + 1))
    out = "/home/claude/be/UAT-commission-plans.xlsx"
    wb.save(out)
    print(out)


if __name__ == "__main__":
    main()
