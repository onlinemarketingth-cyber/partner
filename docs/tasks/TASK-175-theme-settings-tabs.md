# TASK-175 — ThemeSettingsView: four tabs, one screen each

- **Owner:** ag-lead (spec) → ag-ui
- **Date:** 2026-08-12
- **Human:** *"การปรับ theme นั้นใช้การ scroll ถึง 4 ครั้งสำหรับหน้าจอ 2K … อยากให้ปรับอยู่ในแค่ 1 หน้าจอ คำนวนปรับตาม % ความสูงหน้าจอ"*
- **Related:** TASK-055 / ADR-018, ADR-021

---

## 1. The problem

`ThemeSettingsView.vue` stacks **seven sections** vertically in one column: สี · ชุดสีที่บันทึกไว้ ·
ฟอนต์ · โลโก้ · ลิงก์ Login · ชื่อแอปและเมนู · หน้าร้าน. On a 2K display that is four screens of
scrolling to reach the bottom.

The live preview is already `lg:sticky lg:top-20`, so it does stay visible — the problem is
purely the length of the left column.

## 2. Say the honest thing first

**"Fits one screen" does not mean "no scrolling" — it means the scrolling moves inside a
panel.** The content is not being cut; it is being divided. What is actually gained:

- the preview and the save button never leave the viewport
- you always know which subject you are editing, instead of losing your place in a 5,000px column

Anyone promising fewer total pixels is promising to delete settings.

## 3. Decisions (human, 2026-08-12)

**D1 — four tabs across the top**, not a left rail and not an accordion. `CommissionPlansView`
already establishes the tabbed-settings pattern in this app; a left rail costs a third column,
and an accordion still leaves the tallest section (สี, ~400 lines) taller than a screen.

**D2 — the tabs cover THEME ONLY.** The three per-company setting cards (ตั้งค่าวิดีโอ /
การมองเห็นข้อมูลทีม / การแบ่งคอมมิชชั่น) are **not** theme and do **not** become a tab. They stay
as they are, in their own row.

> **Consequence, stated plainly:** the page will still scroll once to reach that row. That is
> the direct cost of D2 and it is the right trade — those three write to three different
> endpoints and have nothing to do with branding. Four scrolls become one.

## 4. Tab layout

| Tab | Sections |
|---|---|
| **สี** | สี · ชุดสีที่บันทึกไว้ |
| **ฟอนต์และโลโก้** | ฟอนต์ · โลโก้ |
| **ชื่อและเมนู** | ชื่อแอปและเมนู |
| **อื่นๆ** | ลิงก์ Login สำหรับตัวแทน · หน้าร้าน (สินค้าแนะนำ) |

- The preview column stays sticky and visible **on every tab** — that is the whole point.
- The header keeps the single save button. **All four tabs are one form and one `PUT /company-theme`;
  switching tabs must never lose an unsaved edit.** Use `v-show`, not `v-if`, or state dies on
  every tab change.

## 5. Height

- Cap the tab panel with **`dvh`, not `vh`** — `vh` counts the collapsible mobile URL bar and
  clips the bottom of the panel.
- **Below ~800px of viewport height, fall back to normal page scrolling.** A locked panel on a
  13" laptop is ~300px tall and worse than what exists today. Better on 2K must not mean worse
  on a laptop.
- Only the panel scrolls; the header, tabs and preview stay put.

## 6. Risk — read before starting

The file is **2,162 lines** and this is a template reorganisation. Earlier today ag-lead deleted
two entire UI cards in a *smaller* move of this exact kind, and `vite build` stayed green
throughout. A build proves nothing here.

**Verify by enumeration, before and after:**

- every section heading, by name
- the count of `<input>`, `<select>` and `IconPicker` in the file — the form must have exactly
  the same controls afterwards
- switch through all four tabs, edit a field on each, and confirm one save persists all of them
- the three settings cards are still present and still saving to their own endpoints

## 7. Acceptance

- [ ] Four tabs; each panel fits a 2K viewport without page scrolling
- [ ] Preview visible on all four tabs
- [ ] One save button; unsaved edits survive tab switches (`v-show`)
- [ ] Viewport shorter than ~800px falls back to normal scrolling
- [ ] The three per-company setting cards are untouched
- [ ] `vue-tsc`, `eslint src`, `vite build` clean; `vitest` still 7/7 green with any new tests kept
