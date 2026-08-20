# TASK-208 — global company switcher in the header, every screen follows it

- **Owner:** ag-lead (ADR-038) → ag-ui (implemented in session) → ag-qa
- **Date:** 2026-08-19
- **Status:** implemented, pending ag-qa + browser UAT
- **Human:** "ในฐานะ Super Admin ผมแยกไม่ออกเลยกำลังแก้สินค้าจากบริษัทไหน ควรจะมีการเลือกบริษัทที่
  ทำงานอยู่บน Head ข้างปุ่มค้นหาด้านขวามือ และทำให้ทั้งระบบมีการปรับตาม และแสดงชื่อบริษัทจะได้ทำงานได้ถูกต้อง"
  Decisions: mode **"1+2"** (a working company AND a ทุกบริษัท view), scope **all 10 screens in one pass**.
- **Related:** ADR-038 (the decision record), BR-6 / Section 5.

## New files

| File | What |
|---|---|
| `stores/activeCompany.ts` | the single scope: `companyId`, `isAllCompanies`, `companyName`, `requiresCompanyPick`, `setCompany`, `loadCompanies` (idempotent), localStorage-persisted |
| `design-system/components/CompanySwitcher.vue` | the control, mounted once in `AdminNavigation` immediately left of the search button (the human's chosen spot). Searchable list, amber when ทุกบริษัท, read-only label for Company Admin |
| `design-system/components/CompanyScopeNotice.vue` | the shared "pick a company first" panel, with a per-screen `action` label |

## Migrated screens (per-screen picker removed)

`ProductCatalogView` (three controls: dialog scope bar, package company filter, banners picker) ·
`ProductEditView` (create-mode select → read-only company line) · `CommissionPlansView` ·
`AcademyManagementView` (two copies) · `ThemeSettingsView` · `VideoSettingsView` ·
`TeamVisibilitySettingsView` · `CommissionSplitSettingsView` · `PolicyReportView` (two report
filters). `QuizLibraryPanel` needed no change — it already takes the company as a prop from
`AcademyManagementView`.

## Verified

- All 12 touched/created SFCs compile clean (`@vue/compiler-sfc`, 0 template errors)
- No backend change, so the backend suite is unaffected (still 1599 passed from TASK-206)

## Still open

- [ ] Browser UAT — **not yet clicked through** (see UAT-014 §9 below to be added)
- [ ] ag-qa: confirm a Company Admin sees a plain label and no picker anywhere, and that nothing
      about their data changed
- [ ] Check the remaining screens that never had a picker but do show cross-company data for a
      Super Admin (e.g. company management, user roster) — they may want the same scope applied
