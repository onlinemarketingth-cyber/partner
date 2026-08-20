# ADR-038: One global "active company" scope for the Admin app

- **Date:** 2026-08-19
- **Status:** Accepted — human decision, both parts of the mode question answered ("1+2")
- **Author:** ag-lead
- **Related:** BR-6 / Section 5 (multi-tenancy), ADR-003 (two independent Vue apps), TASK-202..207
  (the brand/category work that exposed the problem)

## Context

The human, looking at the package list as Super Admin: *"ในฐานะ Super Admin ผมแยกไม่ออกเลยกำลังแก้
สินค้าจากบริษัทไหน ควรจะมีการเลือกบริษัทที่ทำงานอยู่บน Head ... และทำให้ทั้งระบบมีการปรับตาม และแสดงชื่อ
บริษัทจะได้ทำงานได้ถูกต้อง"*.

Ten Admin screens each carried **their own** company `<select>`, each with its own `/companies`
fetch, none sharing state, none surviving a route change:

```
AcademyManagementView  CommissionPlansView   CommissionSplitSettingsView  PolicyReportView
ProductCatalogView(×3) ProductEditView       QuizLibraryPanel(prop)       TeamVisibilitySettingsView
ThemeSettingsView      VideoSettingsView
```

`TenantScope` does not narrow a Super Admin (by design — they are the cross-company operator), so
every list they see is flat and cross-company. With no persistent "who am I working as", the
screens could and did disagree: ProductEditView's create form could be pointed at company A while
the brand/category pickers on the same form listed company B's rows.

This is a **UI-truth** problem, not an authorization one: every request is still Policy- and
TenantScope-checked server-side, so no scope choice here can grant access. What was missing was the
operator knowing which tenant they were about to write to.

## Decision

A single Pinia store, `activeCompany`, is the one answer, with one control (`CompanySwitcher`)
mounted in `AdminNavigation` so it is on screen on every route.

1. **Two states, per the human's "1+2":** a specific company is the working scope, and `null` =
   **ทุกบริษัท** remains available as a read-across view.
2. **Creating is blocked while `null`.** `requiresCompanyPick` is the shared check and
   `CompanyScopeNotice` the shared explanation, so every screen refuses in the same words and
   points at the same control. ทุกบริษัท is styled amber, not brand navy, precisely because it is
   not a normal working state.
3. **Persisted** in `localStorage` (`sva.admin.activeCompanyId`) — the scope survives a refresh,
   which is the thing ten local pickers could never do. A persisted id that no longer resolves is
   dropped back to ทุกบริษัท on load.
4. **Company Admin never sees a picker** — the store pins to their own company from `/me`, and the
   switcher renders a plain read-only label instead. A control that cannot change anything is
   worse than no control.
5. **Screens do not keep private copies.** Every migrated view reads the store; where a file is
   thousands of lines long, a local `computed` alias (`const selectedCompanyId = computed(() =>
   activeCompany.companyId)`) preserves the existing reads without a rename sweep.

## Consequences

- The per-screen "บริษัท" dropdowns are **removed**, including the package-list company filter and
  the brand/category dialog's scope bar built earlier the same day (TASK-202). One control, one
  answer.
- `ProductEditView` no longer asks which company to create in; it states the company (read-only)
  and takes it from the scope, which is also what scopes its brand/category pickers — the
  disagreement described above is now unrepresentable.
- Report screens (`PolicyReportView`) follow the same scope; their own "ทุกบริษัท" options are gone
  because `null` already means exactly that.
- **Not covered:** the Agent Portal (`/frontend`) — an agent belongs to exactly one company, so
  there is nothing to switch. Also not a backend change: no endpoint learns about this store, and
  the security model is untouched (BR-6).
- **Risk accepted:** a deep link to another company's record still opens it while the header shows
  a different scope. The record's own company is displayed on the page (ProductEditView's blue
  bar), rather than silently redirecting or blocking — a Super Admin following a link should see
  what they asked for, labelled.
