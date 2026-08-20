# TASK-210 — ยืนยัน "บันทึกสำเร็จ" หลังปิดโมดัลแก้ไขตัวแทน

**Status:** implemented (2026-08-19) · **Owner:** ag-lead · **Type:** UX fix

## 1. คำขอ (verbatim)

> "กดบันทึก หากบันทึกสำเร็จให้ขึ้นปิดหน้าจอ modal นี้ และขึ้น modal ใหม่ว่าบันทึกสำเร็จ"
> — human, 2026-08-19, on `<AgentEditModal>` (แก้ไขข้อมูลตัวแทน)

## 2. What was actually wrong

`AgentEditModal.submitEdit()` already did half of this: on a 2xx it emitted
`saved` and called `closeModal()`. From the admin's side that is **identical to
the modal being dismissed** — the write lands and the screen simply goes quiet.

The component does have an inline `editSavedMessage` line, but it cannot serve
here: it lives inside the modal that has just been unmounted. Whatever reports
the success has to **outlive the modal**, which means the host.

## 3. Change

### 3.1 New component — `design-system/components/SuccessDialog.vue`

Same visual language as `ConfirmDialog` (glassmorphism card, 16×16 icon well),
but emerald, one button (`ตกลง`), no `confirm` event. Deliberately **not** a
`variant="success"` on `ConfirmDialog`: this dialog asks nothing, and reusing a
two-button component whose second button is meaningless is worse than a 70-line
sibling. `z-[1100]` — one layer above `ConfirmDialog`, because a success dialog
is always raised *by* an action a confirm dialog may still be unwinding.

### 3.2 `AgentEditModal.vue` — the payload carries its own words

```ts
saved: [payload: { leaderChanged: boolean; successMessage?: string }]
```

`successMessage` is present **only on the writes that also close the modal**:

| action | message |
|---|---|
| `submitEdit` (บันทึก) | `บันทึกข้อมูลของ <ชื่อ> เรียบร้อยแล้ว` |
| `confirmDeactivate` | `ปิดใช้งานบัญชีของ <ชื่อ> เรียบร้อยแล้ว` |
| `restoreAgent` | `กู้คืนบัญชีของ <ชื่อ> เรียบร้อยแล้ว` |
| `submitMoveCompany` | `ย้าย <ชื่อ> ไปบริษัท <บริษัท> เรียบร้อยแล้ว` |

`confirmGrantCertification` **omits it on purpose** — that action leaves the
modal open (an admin often grants two tiers in a row), already reports inline,
and a dialog on top of the form still in use would be in the way.

The name in the save message is built from the **form**, not from
`subject.name`: the name is one of the things a save can change, `name` is
derived server-side by `User::booted()`'s `saving()` hook, and the local copy
is still pre-save. Falls back to `subject.name` rather than rendering blank.

### 3.3 Hosts — both of them

`<AgentEditModal>` is mounted in exactly two places (verified by grep):
`AgentRosterView.vue` and `SalesTeamView.vue`. Both now hold
`savedMessage`/`showSavedDialog`, set them in `onAgentEditorSaved`, and render
`<SuccessDialog v-model:show="showSavedDialog" :body="savedMessage" />` inside
`<main>` — a sibling of `<main>` would make the template a multi-root Fragment
and break `App.vue`'s `<Transition mode="out-in">` (same constraint the
existing `ConfirmDialog`s in `SalesTeamView` are written under).

`SalesTeamView.onAgentEditorSaved()` took no argument before; it now takes the
payload. `AgentInviteLinksView` mentions the modal in comments only and mounts
nothing, so it needed no change.

## 4. Verification

- `@vue/compiler-sfc` compile check: 4/4 files OK, 0 template errors.
- `SalesTeamView.spec.ts` stubs `AgentEditModal` and never opens it, so the new
  branch is untouched by it; `SuccessDialog` renders nothing while `show` is
  false, so mounting the view is unaffected.
- **NOT yet verified in a browser** — needs the click-through in §5.

## 5. QA (browser, 2 minutes)

1. จัดการตัวแทน → ✏️ แก้ไข on any agent → change the ชื่อ → **บันทึก**.
   Expected: the edit modal disappears **and** a green "บันทึกสำเร็จ" dialog
   appears naming the agent; press ตกลง; the roster row shows the new name.
2. Press **บันทึก** with nothing changed. Expected: the inline
   "ไม่มีการเปลี่ยนแปลง" line, modal stays open, **no** success dialog.
3. Make the save fail (e.g. clear the email → 422). Expected: the modal stays
   open with the field error, **no** success dialog.
4. ทีมขาย → pencil on a card → save. Same as (1).
5. Section 5 → "อนุมัติระดับโดยไม่ต้องสอบ". Expected: modal stays open, inline
   confirmation only, **no** success dialog (by design).

## 6. Found while verifying — two TASK-208/209 regressions fixed here

Running `vue-tsc --noEmit` over the whole Admin app (which I should have done
when TASK-209 landed, not now) turned up three errors that were mine, one of
them a real runtime bug:

1. **`ThemeSettingsView` was dead for a Super Admin.** TASK-208 replaced the
   view's local `CompanyItem[]` (which carried `slug`) with the global store's
   `CompanyOption` (`{id, name}`). `loadTheme()` reads
   `selectedCompany.value?.slug` and returns early when it is falsy, so the
   theme page fetched **nothing** in Super Admin mode. Fixed by declaring
   `slug: string` on `CompanyOption` — `CompanyResource` has always sent it, so
   the data was there the whole time and only the type was lying.
2. **`ProductCatalogView.readStoredFilters()`** returned its no-stored-value
   default without the new `companyId` key, so the filter object started life
   with `companyId: undefined` instead of `null`.
3. Three `noUncheckedIndexedAccess` errors on `rows[0]` / `group.rows[0]` in
   the TASK-204 grouping code — guarded rather than asserted.

`vue-tsc --noEmit -p tsconfig.app.json` is now **clean** for frontend-admin,
and `eslint` passes on every file this task touched. Adding type-check to the
pre-commit path is worth its own task.

**Not run here:** the vitest suite. `node_modules` on this machine is a macOS
install and the sandbox that reaches it is linux-arm64, so `rolldown`'s native
binding fails to load. Please run `npm run test:unit` locally.
