# TASK-162 — One colour card, divided by rules instead of by cards

- **Owner:** ag-lead (spec) → ag-ui · **Date:** 2026-08-11
- **Human:** *"เลื่อนสีไปอยู่ใน card เดียวกันทั้งหมด ถ้าคุณจะแยก ใช้เส้นเป็นตัวแยก แบบนี้ทำให้ UI สับสน รวมถึงชุดสีที่บันทึกไว้ด้วย"*
- **Scope:** `/frontend-admin/src/views/ThemeSettingsView.vue` only. No backend, no `/frontend`.
- **Related:** TASK-055/ADR-018, TASK-159, TASK-160, TASK-161

---

## 1. Why

Colour settings are spread across four sibling cards — สี, พื้นหลัง, ชุดสีที่บันทึกไว้, and the
colour half of หน้าโหลด. Two rounds of confusion came straight out of that layout:

- the nav-bar colour picker was missed because it sat one row below a near-duplicate label
- the app background was missed entirely because it was two cards further down

Both were reported as "the control is not there". It was there; the layout hid it. Sibling
cards of equal weight read as unrelated topics, so nobody scans past the one they are in.

**One card, hairline rules between groups.**

## 2. Structure

A single `<section>` titled **สี**, groups top to bottom:

| Group label (left column) | Controls |
|---|---|
| แบรนด์ | สีหลัก, สีรอง |
| แถบเมนู | พื้นหลัง (solid/gradient), สีตัวอักษร, สีปุ่มที่เลือกอยู่ |
| การ์ด | พื้นผิว, ตัวอักษร, เส้นขอบ, เงา |
| พื้นหลังแอป | solid / gradient / image (the whole existing control, upload included) |
| หน้าโหลด | สีพื้นหลัง **and** ข้อความหน้าโหลด |
| **ชุดสีที่บันทึกไว้** | list, apply/rename/delete, save-current |

Then delete the now-empty พื้นหลัง, หน้าโหลด and ชุดสี cards. **ฟอนต์ keeps its own card** —
a typeface is not a colour.

### 2.1 The left label column is not decoration

Each group is a row: a fixed-width label column on the left, controls on the right, a
`0.5px` rule above it. **Rules without group labels are the failure mode, not the fix** —
they chop one long list into anonymous chunks and the reader still cannot tell where the
nav-bar settings end and the card settings begin. The label is what gives the rule meaning.

### 2.2 The preset block is separated more strongly

Heavier rule (or `border-strong`) plus a `surface-1` tint, unlike the hairlines between the
five colour groups.

It is not a sixth setting — it is the thing that **acts on all five**. Given the same visual
weight it reads as another group, and then its "ใช้ชุดนี้" button looks like it belongs to
whatever row is nearest. It goes last because it saves what is above it.

### 2.3 หน้าโหลด moves whole (human decision)

I proposed moving only its colour and flagged that it would split one feature across two
places. The human chose to move **both the colour and the message**, so nothing is split
and the separate card disappears.

**ag-lead note, minor:** a card titled "สี" now contains one text field. Accepted — the
group's own label scopes it, and one field does not justify renaming the card or inventing
a second home for it. If it ever grates, rename the card, do not re-split the feature.

## 3. Copy

Trim the preset helper text from two lines to one. Its position inside the colour card
already says what it saves, so the enumeration ("เก็บเฉพาะสี — สีหลัก สีรอง แถบเมนู การ์ด
และพื้นหลัง") is redundant.

**KEEP this line, it is not filler:**

> ระบบจะบันทึกจากค่าที่ **บันทึกลงระบบแล้ว** ไม่ใช่ค่าที่กำลังแก้อยู่บนหน้าจอ

That is a real trap — ag-ui raised it during TASK-161 and it is still true. An admin who
edits, then saves a preset, captures the *previous* values.

## 4. Acceptance criteria

- [ ] One card holds all five colour groups plus presets; no colour control lives outside it
- [ ] Groups separated by hairlines with a persistent left label column
- [ ] Preset block visually distinct from the five groups (heavier rule + tint)
- [ ] พื้นหลัง, หน้าโหลด and ชุดสี cards are gone, not hidden
- [ ] **Every existing control still works** — gradient pickers (nav and app), image upload,
      reset buttons, preset apply/rename/delete, the Super Admin company scoping from
      TASK-161 §5.2, and the live preview
- [ ] Reuse `GradientPicker`; do not fork a third gradient control
- [ ] `ConfirmDialog` still guards apply and delete; no `window.confirm`
- [ ] `vue-tsc` + `eslint src` + `vite build` clean

## 5. Warning to whoever does this

This is a large cut-and-paste inside a ~1900-line file. **ag-lead did exactly this move
badly an hour ago** — sliced the array wrong and silently dropped the presets and font
cards. Count the `<h2>` section headings before and after; the count must be lower by
exactly the three cards being removed and nothing else. Verify each moved control renders,
do not just trust the build.
