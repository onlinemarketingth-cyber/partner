# ADR-052 — "Saved" is said once, by one thing, and only when it is true

- Status: Accepted (owner, 2026-10-01)
- Scope: frontend-admin (all writes), frontend (problem points only). No backend change.

## Context

Owner, 2026-10-01: "รีเฟรชหน้า (F5) แล้วดูว่าค่ายังอยู่ ถ้ายังอยู่แปลว่าบันทึกแล้วจริง
ผมอึดอัดเรื่องนี้ของระบบเราพอสมควรแก้ทั้งหมดเลยได้ ว่าบันทึกส่วนไหนสำเร็จต้องมี Modal
แจ้งการบันทึกสำเร็จ และคุณต้องตรวจว่าบันทึกสำเร็จแล้วไม่ต้องกด F5".

Trigger: UAT-017 failed with "company has no rank settings" although the commission
screen's step rail showed every rank card green. The rail read the rank-settings FORM,
so typed-but-unsaved values looked done.

Audit (code reading, 2026-10-01): 216 writes in the admin app — 8 raised a dialog,
50 printed an inline line, 158 said nothing (form closed silently, list changed, or
nothing). About 40 kept showing typed values rather than stored ones. The agent portal
had 38 agent-page writes, 34 already with a success toast, 1 with nothing, ~9 showing
typed values.

## Decision

1. **One mechanism.** `frontend-admin/src/composables/useSaveFeedback.ts`
   (`confirmSaved`, `notifySaved`) and one `<SaveFeedbackHost>` in `App.vue` rendering
   the existing `SuccessDialog`. No view renders its own success dialog.
2. **The order is the rule:** send → server answers 2xx (else: error, no dialog) →
   screen takes the server's values (response or re-read) → dialog. A dialog quoting a
   value quotes the server's value.
3. **Admin: a modal on every write** — save, create, edit, delete, approve, toggle, pin,
   drag-reorder (owner chose "Modal ทุกจุด").
4. **Re-read failed after a successful write** → the dialog says it saved but the screen
   may be behind (`SAVED_BUT_STALE_BODY`), never a plain success.
5. **Partial saves** (one button, several writes, some failed) → dialog titled
   "บันทึกแล้วบางส่วน" naming what was stored, plus the inline error for what was not.
6. **Status reads saved data.** Checklists, rails, badges and answers read stored refs;
   only labelled live previews may read a form.
7. **Every delete / destructive revoke asks first** with `ConfirmDialog` (danger).
   `window.confirm` / `window.prompt` removed from the admin app. Reject/revoke/cancel
   actions that already require a typed reason in an inline panel keep that panel as
   their confirmation step.
8. **Agent portal keeps its toasts** (owner choice); fixed only the point with no
   feedback and the points that kept typed values.

## Consequences

- New writes get the behaviour by calling `confirmSaved`; specs assert
  `saveFeedbackState` (reset before every test in `vitest.setup.ts`).
- Admins confirm a modal after every action, including reorders — accepted by the owner.
- Known follow-up (backend, not in scope): `GET /affiliate-links` still lists revoked
  links (no `revoked_at` filter / `is_usable` field). The portal hides the just-revoked
  id after reload as a stopgap marked `TODO: CONFIRM (backend gap)`.
- Pre-existing, unchanged: intermittent failures in
  `CommissionResolutionMatrix.spec` ("the table is the product list…") on the untouched
  baseline as well; lint `vitest/no-identical-title` in `CommissionNavigation.spec.ts`.
